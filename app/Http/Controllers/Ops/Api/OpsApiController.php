<?php

namespace App\Http\Controllers\Ops\Api;

use App\Http\Controllers\Ops\OpsController;
use App\Http\Requests\Ops\ServiceRequestSubmission;
use App\Models\Client;
use App\Models\Product;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Services\Ops\DashboardMetrics;
use App\Services\Ops\InventoryService;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\PurchaseRequestService;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\ServiceRequestService;
use App\Services\Ops\WarrantyService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON API for the PCFix operations module (X-API-TOKEN auth via token_auth).
 * Ids are Invoice Ninja hashed ids. Same services + abilities as the console.
 */
class OpsApiController extends OpsController
{
    use MakesHash;

    private function sr(ServiceRequest $sr): array
    {
        return [
            'id' => $sr->hashed_id,
            'number' => $sr->request_number,
            'status' => $sr->status,
            'channel' => $sr->channel,
            'customer_name' => $sr->customer_name,
            'email' => $sr->email,
            'phone' => $sr->phone,
            'client_id' => $sr->client_id ? $this->encodePrimaryKey($sr->client_id) : null,
            'device' => ['type' => $sr->device_type, 'brand' => $sr->device_brand, 'model' => $sr->device_model, 'serial_number' => $sr->serial_number],
            'reported_issue' => $sr->reported_issue,
            'preferred_service_date' => $sr->preferred_service_date?->toDateString(),
            'possible_duplicate' => $sr->possible_duplicate,
            'repair_job_id' => $sr->repair_job_id ? $this->encodePrimaryKey($sr->repair_job_id) : null,
            'quote_id' => $sr->quote_id ? $this->encodePrimaryKey($sr->quote_id) : null,
            'created_at' => $sr->created_at?->toIso8601String(),
        ];
    }

    private function job(RepairJob $j): array
    {
        return [
            'id' => $j->hashed_id,
            'number' => $j->job_number,
            'status' => $j->status,
            'priority' => $j->priority,
            'required_skill' => $j->required_skill,
            'client_id' => $this->encodePrimaryKey($j->client_id),
            'assigned_user_id' => $j->assigned_user_id ? $this->encodePrimaryKey($j->assigned_user_id) : null,
            'quote_id' => $j->quote_id ? $this->encodePrimaryKey($j->quote_id) : null,
            'invoice_id' => $j->invoice_id ? $this->encodePrimaryKey($j->invoice_id) : null,
            'is_warranty_job' => $j->is_warranty_job,
            'reported_issue' => $j->reported_issue,
            'diagnosis' => $j->diagnosis,
            'started_at' => $j->started_at?->toIso8601String(),
            'completed_at' => $j->completed_at?->toIso8601String(),
            'created_at' => $j->created_at?->toIso8601String(),
        ];
    }

    private function scopedJob(string $id, bool $work = false): RepairJob
    {
        $this->ensure('repair_jobs.view_all', 'repair_jobs.work');
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $assigned = (int) $job->assigned_user_id === (int) $this->user()->id;

        abort_unless($work
            ? ($this->can('repair_jobs.work') && ($assigned || $this->can('repair_jobs.manage')))
            : ($this->can('repair_jobs.view_all') || $assigned), 403);

        return $job;
    }

    public function dashboard(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'grain' => 'nullable|in:day,week,month,year']);
        $m = DashboardMetrics::forRange($this->company(), $request->input('from'), $request->input('to'));

        return response()->json(['data' => [
            'range' => ['from' => $m->from()->toDateString(), 'to' => $m->to()->toDateString(), 'currency' => $m->currencyCode()],
            'financial' => $m->financial(),
            'sales_trend' => $m->salesTrend($request->input('grain', 'month')),
            'repairs' => $m->repairs(),
            'inventory' => $m->inventory(),
            'customers' => $m->customers(),
        ]]);
    }

    public function serviceRequests(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(ServiceRequest::STATUSES))], 'per_page' => 'nullable|integer|min:1|max:100']);

        $q = ServiceRequest::query()->forCompany($this->companyId())->orderByDesc('id');

        if ($s = $request->input('status')) {
            $q->where('status', $s);
        }

        $page = $q->paginate($request->integer('per_page', 25));

        return response()->json(['data' => collect($page->items())->map(fn ($sr) => $this->sr($sr)), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function serviceRequest(string $id)
    {
        return response()->json(['data' => $this->sr(ServiceRequest::forCompanyOrFail($this->companyId(), $id))]);
    }

    public function storeServiceRequest(ServiceRequestSubmission $request, ServiceRequestService $service)
    {
        [$sr, $dup] = $service->submit($this->company(), $request->payload(), $request->input('channel', 'phone'), null, $this->user(), $request->ip());

        return response()->json(['data' => $this->sr($sr), 'duplicate_submission' => $dup], $dup ? 200 : 201);
    }

    public function repairJobs(Request $request)
    {
        $this->ensure('repair_jobs.view_all', 'repair_jobs.work');
        $request->validate(['status' => ['nullable', Rule::in(array_keys(config('pcfix.repair_statuses')))], 'per_page' => 'nullable|integer|min:1|max:100']);

        $q = RepairJob::query()->forCompany($this->companyId())->orderByDesc('id');

        if (OpsAccess::isRestrictedToAssigned($this->user())) {
            $q->where('assigned_user_id', $this->user()->id);
        }

        if ($s = $request->input('status')) {
            $q->where('status', $s);
        }

        $page = $q->paginate($request->integer('per_page', 25));

        return response()->json(['data' => collect($page->items())->map(fn ($j) => $this->job($j)), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function repairJob(string $id)
    {
        $job = $this->scopedJob($id);

        return response()->json(['data' => $this->job($job) + [
            'parts' => $job->parts()->with('product')->get()->map(fn ($p) => ['product_key' => $p->product->product_key, 'quantity' => $p->quantity, 'unit_price' => (float) $p->unit_price]),
            'updates' => $job->updates()->limit(50)->get()->map(fn ($u) => ['type' => $u->type, 'from' => $u->from_status, 'to' => $u->to_status, 'note' => $u->note, 'customer_visible' => $u->is_customer_visible, 'at' => $u->created_at?->toIso8601String()]),
        ]]);
    }

    public function repairJobStatus(Request $request, string $id, RepairJobService $jobs)
    {
        $job = $this->scopedJob($id, true);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'waiting_for_parts', 'cancelled'])], 'note' => 'nullable|string|max:1000']);

        if ($data['status'] === 'cancelled') {
            $this->ensure('repair_jobs.manage');
        }

        return response()->json(['data' => $this->job($jobs->transition($job, $data['status'], $this->user(), $data['note'] ?? null))]);
    }

    public function assignRepairJob(Request $request, string $id, RepairJobService $jobs)
    {
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['technician_id' => 'nullable|string|max:40', 'reason' => 'nullable|string|max:500']);

        if (! empty($data['technician_id'])) {
            $uid = $this->decodePrimaryKey($data['technician_id'], true);
            abort_unless(is_numeric($uid), 422);
            $jobs->assignManual($job, (int) $uid, $this->user(), $data['reason'] ?? null);
        } elseif (! $jobs->autoAssign($job, $this->user())) {
            return response()->json(['message' => 'No eligible technician available.'], 409);
        }

        return response()->json(['data' => $this->job($job->fresh())]);
    }

    public function inventory(Request $request, InventoryService $inventory)
    {
        $request->validate(['low' => 'nullable|boolean']);

        $q = $request->boolean('low')
            ? $inventory->lowStockQuery($this->companyId())
            : Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->where('track_stock', true);

        return response()->json(['data' => $q->orderBy('product_key')->limit(500)->get()->map(fn ($p) => [
            'id' => $p->hashed_id,
            'sku' => $p->product_key,
            'name' => $p->notes,
            'category' => $p->category,
            'quantity' => (int) $p->in_stock_quantity,
            'minimum' => (int) $p->stock_notification_threshold,
            'unit_cost' => (float) $p->cost,
            'price' => (float) $p->price,
            'storage_location' => $p->storage_location,
            'supplier_id' => $p->vendor_id ? $this->encodePrimaryKey($p->vendor_id) : null,
            'low_stock' => $inventory->isLow($p),
        ])]);
    }

    public function adjustInventory(Request $request, string $id, InventoryService $inventory)
    {
        $pid = $this->decodePrimaryKey($id, true);
        abort_unless(is_numeric($pid), 404);
        $product = Product::query()->where('company_id', $this->companyId())->whereKey($pid)->firstOrFail();

        $data = $request->validate(['quantity' => 'required|integer|not_in:0|min:-100000|max:100000', 'notes' => 'required|string|max:500']);

        $m = $inventory->move($product, (int) $data['quantity'], $data['quantity'] > 0 ? 'stock_in' : 'stock_out', $this->user()->id, $data['notes']);

        return response()->json(['data' => ['balance' => $m->balance_after, 'movement_id' => $m->hashed_id]]);
    }

    public function warrantyCheck(Request $request, WarrantyService $service)
    {
        $data = $request->validate(['client_id' => 'required|string|max:40', 'serial_number' => 'nullable|string|max:150']);

        $cid = $this->decodePrimaryKey($data['client_id'], true);
        $client = is_numeric($cid) ? Client::query()->where('company_id', $this->companyId())->find($cid) : null;
        abort_unless($client, 404);

        return response()->json(['data' => $service->checkEligibility($this->companyId(), $client->id, $data['serial_number'] ?? null)]);
    }

    public function purchaseRequests(Request $request)
    {
        $this->ensure('purchase_requests.create', 'purchase_requests.approve', 'purchase_requests.receive');

        $q = PurchaseRequest::query()->forCompany($this->companyId())->with('items')->orderByDesc('id');

        if (! $this->can('purchase_requests.approve') && ! $this->can('purchase_requests.receive')) {
            $q->where('user_id', $this->user()->id);
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn ($pr) => [
            'id' => $pr->hashed_id,
            'number' => $pr->request_number,
            'type' => $pr->type,
            'status' => $pr->status,
            'source' => $pr->source,
            'total' => $pr->total(),
            'items' => $pr->items->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_cost' => (float) $i->unit_cost, 'received' => $i->received_quantity]),
        ])]);
    }

    public function decidePurchaseRequest(Request $request, string $id, PurchaseRequestService $service)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'notes' => 'required_if:decision,reject|nullable|string|max:1000']);

        $pr = $service->decide($pr, $this->user(), $data['decision'] === 'approve', $data['notes'] ?? null);

        return response()->json(['data' => ['id' => $pr->hashed_id, 'status' => $pr->status]]);
    }
}
