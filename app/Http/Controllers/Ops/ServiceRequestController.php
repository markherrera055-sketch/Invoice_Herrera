<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Ops\Concerns\ParsesQuoteLines;
use App\Http\Requests\Ops\ServiceRequestSubmission;
use App\Models\Client;
use App\Models\Product;
use App\Models\Ops\ServiceRequest;
use App\Services\Ops\CustomerMatcher;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\ServiceRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceRequestController extends OpsController
{
    use ParsesQuoteLines;

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(ServiceRequest::STATUSES))],
            'q' => 'nullable|string|max:100',
            'duplicates' => 'nullable|boolean',
        ]);

        $query = ServiceRequest::query()->forCompany($this->companyId())->with(['client', 'repairJob']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('duplicates')) {
            $query->where('possible_duplicate', true);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('request_number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%");
            });
        }

        $counts = ServiceRequest::query()->forCompany($this->companyId())->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return view('ops::service_requests.index', [
            'requests' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'counts' => $counts,
        ]);
    }

    public function create()
    {
        return view('ops::service_requests.create');
    }

    public function store(ServiceRequestSubmission $request, ServiceRequestService $service)
    {
        [$sr, $dup] = $service->submit($this->company(), $request->payload(), $request->input('channel', 'walk_in'), null, $this->user(), $request->ip());

        return redirect()->route('ops.service_requests.show', $sr->hashed_id)
            ->with('ops_success', $dup ? "This request was already recorded as {$sr->request_number}." : "Service request {$sr->request_number} created.");
    }

    public function show(string $id, CustomerMatcher $matcher)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id)->load(['client.contacts', 'device', 'repairJob', 'quote']);

        // Refresh candidates for unlinked requests so staff see current data.
        $candidates = $sr->client_id ? [] : $matcher->findCandidates($this->companyId(), $sr->email, $sr->phone, $sr->customer_name, $sr->address);

        return view('ops::service_requests.show', [
            'sr' => $sr,
            'candidates' => $candidates,
            'products' => Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('product_key')->get(['id', 'product_key', 'notes', 'price', 'in_stock_quantity', 'track_stock']),
        ]);
    }

    public function update(ServiceRequestSubmission $request, string $id, ServiceRequestService $service)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id);
        $service->update($sr, $request->payload(), $this->user());

        return $this->done('Service request updated.');
    }

    public function linkClient(Request $request, string $id, ServiceRequestService $service)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['client' => 'required|string|max:40']);

        $client = Client::query()->where('company_id', $this->companyId())->find($this->decodePrimaryKey($data['client'], true));
        abort_unless($client, 404);

        $service->linkClient($sr, $client->id, $this->user());

        return $this->done("Linked to existing customer {$client->present()->name()}. No duplicate record was created.");
    }

    public function createClient(string $id, ServiceRequestService $service)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id);
        $sr = $service->createClientFor($sr, $this->user());

        return $this->done("New customer record created for {$sr->customer_name}.");
    }

    /**
     * Technician evaluation: record diagnosis, create the repair job and send
     * the quotation (Invoice Ninja quote) to the customer for approval.
     */
    public function quote(Request $request, string $id, RepairJobService $jobs)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id);

        $data = $request->validate([
            'diagnosis' => 'required|string|min:5|max:3000',
            'priority' => ['required', Rule::in(array_keys(config('pcfix.priorities')))],
            'required_skill' => ['nullable', Rule::in(array_keys(config('pcfix.skills')))],
        ]);

        $lines = $this->parseQuoteLines($request, $this->companyId());

        abort_if(in_array($sr->status, ['cancelled', 'rejected'], true), 422, 'This request is closed.');

        if ($sr->quote_id && $sr->repairJob && $sr->repairJob->status !== 'pending') {
            return back()->withErrors(['quote' => 'A quotation has already been issued for this request.']);
        }

        $job = $jobs->createFromServiceRequest($sr, $this->user(), ['priority' => $data['priority'], 'required_skill' => $data['required_skill'] ?? null]);
        $jobs->addNote($job, $this->user(), $data['diagnosis'], true, 'diagnosis');
        $quote = $jobs->issueInitialQuote($job->fresh(), $this->user(), $lines, 'Initial diagnosis: ' . $data['diagnosis']);

        return redirect()->route('ops.repair_jobs.show', $job->hashed_id)
            ->with('ops_success', "Repair job {$job->job_number} created and quotation {$quote->number} sent to the customer.");
    }

    public function cancel(Request $request, string $id, ServiceRequestService $service)
    {
        $sr = ServiceRequest::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $service->cancel($sr, $this->user(), $data['reason']);

        return $this->done('Request cancelled.');
    }
}
