<?php

namespace App\Http\Controllers\Ops;

use App\Models\Client;
use App\Models\Ops\Warranty;
use App\Models\Ops\WarrantyClaim;
use App\Services\Ops\AuditLogger;
use App\Services\Ops\WarrantyService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarrantyController extends OpsController
{
    use MakesHash;

    public function index(Request $request, WarrantyService $service)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(Warranty::STATUSES))], 'q' => 'nullable|string|max:100']);

        $query = Warranty::query()->forCompany($this->companyId())->with(['client', 'device', 'repairJob']);

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }

        if ($q = $request->input('q')) {
            $query->where(fn ($w) => $w->where('warranty_number', 'like', "%{$q}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                ->orWhereHas('device', fn ($d) => $d->where('serial_number', 'like', "%{$q}%")));
        }

        $counts = Warranty::query()->forCompany($this->companyId())->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return view('ops::warranty.index', ['warranties' => $query->orderBy('end_date')->paginate(25)->withQueryString(), 'counts' => $counts]);
    }

    public function show(string $id, WarrantyService $service)
    {
        $w = Warranty::forCompanyOrFail($this->companyId(), $id);
        $service->refreshStatus($w);

        return view('ops::warranty.show', ['w' => $w->load(['client', 'device', 'repairJob', 'invoice', 'claims.repairJob'])]);
    }

    public function update(Request $request, string $id, WarrantyService $service)
    {
        $w = Warranty::forCompanyOrFail($this->companyId(), $id);

        $data = $request->validate([
            'coverage_type' => ['required', Rule::in(array_keys(Warranty::COVERAGE))],
            'end_date' => 'required|date|after_or_equal:' . $w->start_date->toDateString(),
            'covered_items' => 'nullable|string|max:3000',
            'exclusions' => 'nullable|string|max:3000',
            'void' => 'nullable|boolean',
        ]);

        $before = $w->only(['coverage_type', 'end_date', 'status']);
        $w->fill(collect($data)->except('void')->all());

        if ($request->boolean('void')) {
            $w->status = 'void';
        } elseif ($w->status === 'void') {
            $w->status = 'active';
        }

        $w->save();
        $service->refreshStatus($w);

        AuditLogger::log($this->companyId(), 'warranty.updated', $w, ['before' => $before, 'after' => $w->only(['coverage_type', 'end_date', 'status'])], $this->user()->id);

        return $this->done('Warranty updated.');
    }

    public function claims(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(WarrantyClaim::STATUSES))]]);

        $query = WarrantyClaim::query()->forCompany($this->companyId())->with(['client', 'warranty', 'repairJob']);

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }

        return view('ops::warranty.claims', [
            'claims' => $query->orderByRaw("status = 'submitted' DESC")->orderByDesc('id')->paginate(25)->withQueryString(),
            'clients' => $this->can('warranty.manage') ? Client::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
        ]);
    }

    public function showClaim(string $id)
    {
        $claim = WarrantyClaim::forCompanyOrFail($this->companyId(), $id)->load(['client', 'contact', 'warranty.repairJob', 'device', 'repairJob', 'verifier']);

        return view('ops::warranty.claim', ['claim' => $claim]);
    }

    /** Staff-entered claim (phone / walk-in). */
    public function storeClaim(Request $request, WarrantyService $service)
    {
        $data = $request->validate([
            'client' => 'required|string|max:40',
            'serial_number' => 'nullable|string|max:150',
            'issue_description' => 'required|string|min:5|max:3000',
        ]);

        $client = Client::query()->where('company_id', $this->companyId())->where('is_deleted', false)->find($this->decodePrimaryKey($data['client'], true));
        abort_unless($client, 404);

        $claim = $service->submitClaim($this->company(), $client, $client->contacts()->first(), $data, 'staff', $this->user());

        return redirect()->route('ops.warranty.claims.show', $claim->hashed_id)->with('ops_success', "Claim {$claim->claim_number} recorded. {$claim->eligibility['reason']}");
    }

    public function decide(Request $request, string $id, WarrantyService $service)
    {
        $claim = WarrantyClaim::forCompanyOrFail($this->companyId(), $id);

        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'notes' => 'required|string|min:3|max:2000',
            'create_paid_job' => 'nullable|boolean',
        ]);

        $claim = $service->decide($claim, $this->user(), $data['decision'] === 'approve', $data['notes'], $request->boolean('create_paid_job', true));

        return $this->done($data['decision'] === 'approve'
            ? 'Claim approved. Warranty repair job ' . ($claim->repairJob?->job_number ?? '') . ' created.'
            : 'Claim rejected.' . ($claim->repairJob ? " Repair job {$claim->repairJob->job_number} created for a paid quotation." : ''));
    }

    /** Quick eligibility lookup by serial number (JSON for the console search box). */
    public function check(Request $request, WarrantyService $service)
    {
        $data = $request->validate(['serial' => 'required|string|max:150']);

        $warranties = Warranty::query()->forCompany($this->companyId())
            ->whereHas('device', fn ($d) => $d->where('serial_number', trim($data['serial'])))
            ->with('client')
            ->get();

        $results = $warranties->groupBy('client_id')->map(function ($group) use ($service, $data) {
            $client = $group->first()->client;
            $result = $service->checkEligibility($this->companyId(), $client->id, $data['serial']);

            return ['client' => $client->present()->name()] + $result;
        })->values();

        return view('ops::warranty.check', ['serial' => $data['serial'], 'results' => $results]);
    }
}
