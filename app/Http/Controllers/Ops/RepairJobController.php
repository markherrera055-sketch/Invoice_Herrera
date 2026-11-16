<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Ops\Concerns\ParsesQuoteLines;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\User;
use App\Models\Ops\RepairJob;
use App\Models\Ops\RepairJobPart;
use App\Models\Ops\TechnicianProfile;
use App\Services\Ops\AuditLogger;
use App\Services\Ops\InvoiceGenerator;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\TechnicianAssigner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepairJobController extends OpsController
{
    use ParsesQuoteLines;

    /**
     * Record-level authorization:
     *  - view: anyone with view_all, or the assigned technician
     *  - work: repair_jobs.work AND (assigned technician OR manager)
     */
    private function job(string $id, string $mode = 'view'): RepairJob
    {
        $this->ensure('repair_jobs.view_all', 'repair_jobs.work');

        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $isAssigned = (int) $job->assigned_user_id === (int) $this->user()->id;
        $isManager = $this->can('repair_jobs.manage');

        $allowed = match ($mode) {
            'view' => $this->can('repair_jobs.view_all') || $isAssigned,
            'work' => $this->can('repair_jobs.work') && ($isAssigned || $isManager),
            default => false,
        };

        abort_unless($allowed, 403, 'This repair job is not assigned to you.');

        return $job;
    }

    public function index(Request $request)
    {
        $this->ensure('repair_jobs.view_all', 'repair_jobs.work');

        $request->validate([
            'status' => ['nullable', Rule::in(array_merge(array_keys(config('pcfix.repair_statuses')), ['open']))],
            'priority' => ['nullable', Rule::in(array_keys(config('pcfix.priorities')))],
            'technician' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
        ]);

        $query = RepairJob::query()->forCompany($this->companyId())->with(['client', 'device', 'technician']);

        if (OpsAccess::isRestrictedToAssigned($this->user())) {
            $query->where('assigned_user_id', $this->user()->id);
        } elseif ($t = $request->input('technician')) {
            $query->where('assigned_user_id', $t);
        }

        $status = $request->input('status', 'open');
        $status === 'open' ? $query->whereIn('status', RepairJob::OPEN_STATUSES) : $query->where('status', $status);

        if ($p = $request->input('priority')) {
            $query->where('priority', $p);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('job_number', 'like', "%{$q}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('device', fn ($d) => $d->where('serial_number', 'like', "%{$q}%"));
            });
        }

        return view('ops::repair_jobs.index', [
            'jobs' => $query->orderByRaw("FIELD(priority,'urgent','high','normal','low')")->orderBy('created_at')->paginate(30)->withQueryString(),
            'status' => $status,
            'technicians' => $this->can('repair_jobs.view_all') ? TechnicianProfile::query()->forCompany($this->companyId())->with('user')->get() : collect(),
        ]);
    }

    public function show(string $id, TechnicianAssigner $assigner, InvoiceGenerator $billing)
    {
        $job = $this->job($id)->load(['client.contacts', 'device', 'serviceRequest', 'technician', 'invoice', 'warrantyClaim.warranty', 'warranty', 'parts.product', 'parts.user', 'updates.user']);

        $isManager = $this->can('repair_jobs.manage');

        return view('ops::repair_jobs.show', [
            'job' => $job,
            'quotes' => $job->quoteLinks()->with('quote')->orderBy('id')->get(),
            'ranking' => $isManager ? $assigner->rank($job) : collect(),
            'canWork' => $this->can('repair_jobs.work') && ((int) $job->assigned_user_id === (int) $this->user()->id || $isManager),
            'isManager' => $isManager,
            'products' => Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('product_key')->get(['id', 'product_key', 'notes', 'price', 'in_stock_quantity', 'track_stock']),
            'unquoted' => $billing->unquotedParts($job),
            'history' => $job->device_id ? RepairJob::query()->forCompany($this->companyId())->where('device_id', $job->device_id)->where('id', '!=', $job->id)->orderByDesc('id')->limit(10)->get() : collect(),
        ]);
    }

    public function quote(Request $request, string $id, RepairJobService $jobs)
    {
        $this->ensure('service_requests.manage', 'repair_jobs.manage');
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);

        abort_unless($job->status === 'pending', 422, 'A quotation can only be issued for a pending job.');

        $lines = $this->parseQuoteLines($request, $this->companyId());
        $quote = $jobs->issueInitialQuote($job, $this->user(), $lines, (string) $request->input('notes', ''));

        return $this->done("Quotation {$quote->number} sent to the customer.");
    }

    public function status(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'waiting_for_parts', 'cancelled'])],
            'note' => 'nullable|string|max:1000',
        ]);

        if ($data['status'] === 'cancelled') {
            $this->ensure('repair_jobs.manage');
        }

        $jobs->transition($job, $data['status'], $this->user(), $data['note'] ?? null);

        return $this->done('Status updated to ' . config('pcfix.repair_statuses')[$data['status']] . '.');
    }

    public function note(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $data = $request->validate([
            'note' => 'required|string|max:3000',
            'type' => 'required|in:note,diagnosis',
            'customer_visible' => 'nullable|boolean',
        ]);

        $jobs->addNote($job, $this->user(), $data['note'], $request->boolean('customer_visible'), $data['type']);

        return $this->done($data['type'] === 'diagnosis' ? 'Diagnosis recorded.' : 'Work note added.');
    }

    public function addPart(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $data = $request->validate(['product' => 'required|string|max:40', 'quantity' => 'required|integer|min:1|max:1000']);

        $product = Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->find($this->decodePrimaryKey($data['product'], true));
        abort_unless($product, 404);

        $jobs->addPart($job, $product, (int) $data['quantity'], $this->user());

        return $this->done("Recorded {$data['quantity']} x {$product->product_key}; stock updated.");
    }

    public function removePart(string $id, string $part, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $row = RepairJobPart::forCompanyOrFail($this->companyId(), $part);
        abort_unless((int) $row->repair_job_id === (int) $job->id, 404);

        $jobs->removePart($row, $this->user());

        return $this->done('Part returned to stock.');
    }

    public function revision(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $lines = $this->parseQuoteLines($request, $this->companyId());

        $quote = $jobs->requestRevision($job, $this->user(), $lines, $request->input('reason'));

        return $this->done("Revised quotation {$quote->number} sent. Work pauses until the customer approves.");
    }

    public function complete(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->job($id, 'work');
        $data = $request->validate(['summary' => 'required|string|min:5|max:3000']);

        if (! in_array($job->status, ['in_progress'], true)) {
            return back()->withErrors(['status' => 'Start the job (In Progress) before completing it.']);
        }

        $jobs->complete($job, $this->user(), $data['summary']);

        return $this->done('Repair marked as completed. Accounting has been notified.');
    }

    public function assign(Request $request, string $id, RepairJobService $jobs)
    {
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['technician' => 'required|integer', 'reason' => 'nullable|string|max:500']);

        $tech = $jobs->assignManual($job, (int) $data['technician'], $this->user(), $data['reason'] ?? null);

        return $this->done("Assigned to {$tech->present()->name()} (manual override).");
    }

    public function autoAssign(string $id, RepairJobService $jobs)
    {
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $tech = $jobs->autoAssign($job, $this->user());

        return $tech
            ? $this->done("Automatically assigned to {$tech->present()->name()}.")
            : back()->withErrors(['technician' => 'No eligible technician is available. Assign manually or update technician availability.']);
    }

    public function priority(Request $request, string $id, RepairJobService $jobs)
    {
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate([
            'priority' => ['required', Rule::in(array_keys(config('pcfix.priorities')))],
            'required_skill' => ['nullable', Rule::in(array_keys(config('pcfix.skills')))],
        ]);

        $jobs->updatePriority($job, $this->user(), $data['priority'], $data['required_skill'] ?? null);

        return $this->done('Job priority / skill updated.');
    }

    public function history(Request $request)
    {
        $this->ensure('repair_jobs.view_all', 'repair_jobs.work');
        $request->validate(['q' => 'nullable|string|max:100']);

        $query = RepairJob::query()->forCompany($this->companyId())->whereIn('status', ['completed', 'cancelled'])->with(['client', 'device', 'technician', 'invoice']);

        if (OpsAccess::isRestrictedToAssigned($this->user())) {
            $query->where('assigned_user_id', $this->user()->id);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('job_number', 'like', "%{$q}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('device', fn ($d) => $d->where('serial_number', 'like', "%{$q}%")->orWhere('model', 'like', "%{$q}%"));
            });
        }

        return view('ops::repair_jobs.history', ['jobs' => $query->orderByDesc('completed_at')->orderByDesc('id')->paginate(30)->withQueryString()]);
    }

    public function technicians()
    {
        $cid = $this->companyId();

        $users = CompanyUser::query()->where('company_id', $cid)->whereNull('deleted_at')->with('user')->get()
            ->filter(fn ($cu) => $cu->user && OpsAccess::can($cu, 'repair_jobs.work'));

        $profiles = TechnicianProfile::query()->forCompany($cid)->get()->keyBy('user_id');

        $workload = RepairJob::query()->forCompany($cid)->whereIn('status', RepairJob::ACTIVE_STATUSES)->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) c')->groupBy('assigned_user_id')->pluck('c', 'assigned_user_id');

        $completed = RepairJob::query()->forCompany($cid)->where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))
            ->selectRaw('assigned_user_id, COUNT(*) c')->groupBy('assigned_user_id')->pluck('c', 'assigned_user_id');

        return view('ops::repair_jobs.technicians', compact('users', 'profiles', 'workload', 'completed'));
    }

    public function updateTechnician(Request $request, int $user)
    {
        $cu = CompanyUser::query()->where('company_id', $this->companyId())->where('user_id', $user)->whereNull('deleted_at')->firstOrFail();

        $data = $request->validate([
            'skills' => 'nullable|array',
            'skills.*' => ['string', Rule::in(array_keys(config('pcfix.skills')))],
            'branch' => ['nullable', Rule::in(config('pcfix.branches'))],
            'is_available' => 'nullable|boolean',
            'max_active_jobs' => 'required|integer|min:1|max:30',
        ]);

        $profile = TechnicianProfile::query()->forCompany($this->companyId())->where('user_id', $cu->user_id)->first() ?? new TechnicianProfile();
        $profile->company_id = $this->companyId();
        $profile->user_id = $cu->user_id;
        $profile->fill([
            'skills' => array_values($data['skills'] ?? []),
            'branch' => $data['branch'] ?? null,
            'is_available' => $request->boolean('is_available'),
            'max_active_jobs' => $data['max_active_jobs'],
        ])->save();

        AuditLogger::log($this->companyId(), 'technician.updated', $profile, $profile->only(['skills', 'branch', 'is_available', 'max_active_jobs']), $this->user()->id);

        return $this->done('Technician profile saved.');
    }
}
