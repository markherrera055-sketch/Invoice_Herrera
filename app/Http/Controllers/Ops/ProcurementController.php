<?php

namespace App\Http\Controllers\Ops;

use App\Factory\VendorFactory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\Ops\PurchaseRequest;
use App\Repositories\VendorRepository;
use App\Services\Ops\AuditLogger;
use App\Services\Ops\PurchaseRequestService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 5B/5C: suppliers (Invoice Ninja vendors), purchase requests with
 * approval, purchase orders (Invoice Ninja module) and supplier expenses.
 */
class ProcurementController extends OpsController
{
    use MakesHash;

    private function vendor(string $id): Vendor
    {
        $vid = $this->decodePrimaryKey($id, true);
        abort_unless(is_numeric($vid), 404);

        return Vendor::query()->where('company_id', $this->companyId())->whereKey($vid)->firstOrFail();
    }

    /* ----------------------------- Suppliers ----------------------------- */

    public function suppliers(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:100']);

        $query = Vendor::query()->where('company_id', $this->companyId())->where('is_deleted', false)->with('contacts')
            ->withCount(['expenses as expense_count' => fn ($q) => $q->where('is_deleted', false)]);

        if ($q = $request->input('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhereHas('contacts', fn ($c) => $c->where('email', 'like', "%{$q}%")));
        }

        $productCounts = Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, COUNT(*) c')->groupBy('vendor_id')->pluck('c', 'vendor_id');

        return view('ops::procurement.suppliers', ['vendors' => $query->orderBy('name')->paginate(25)->withQueryString(), 'productCounts' => $productCounts]);
    }

    public function createSupplier()
    {
        return view('ops::procurement.supplier_form', ['vendor' => null]);
    }

    public function storeSupplier(Request $request)
    {
        $data = $this->supplierData($request);

        $vendor = app(VendorRepository::class)->save([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? '',
            'address1' => $data['address'] ?? '',
            'private_notes' => $data['products_supplied'] ?? '',
            'contacts' => [[
                'first_name' => $data['contact_first_name'] ?? '',
                'last_name' => $data['contact_last_name'] ?? '',
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
            ]],
        ], VendorFactory::create($this->companyId(), $this->user()->id));

        AuditLogger::log($this->companyId(), 'supplier.created', $vendor, ['name' => $vendor->name], $this->user()->id);

        return redirect()->route('ops.suppliers.show', $vendor->hashed_id)->with('ops_success', 'Supplier created.');
    }

    public function showSupplier(string $id)
    {
        $vendor = $this->vendor($id)->load('contacts');
        $cid = $this->companyId();

        return view('ops::procurement.supplier_show', [
            'vendor' => $vendor,
            'products' => Product::query()->where('company_id', $cid)->where('vendor_id', $vendor->id)->where('is_deleted', false)->orderBy('product_key')->get(),
            'expenses' => Expense::query()->where('company_id', $cid)->where('vendor_id', $vendor->id)->where('is_deleted', false)->with('category')->orderByDesc('date')->limit(25)->get(),
            'expenseTotal' => (float) Expense::query()->where('company_id', $cid)->where('vendor_id', $vendor->id)->where('is_deleted', false)->sum('amount'),
            'unpaidTotal' => (float) Expense::query()->where('company_id', $cid)->where('vendor_id', $vendor->id)->where('is_deleted', false)->whereNull('payment_date')->sum('amount'),
            'orders' => PurchaseOrder::query()->where('company_id', $cid)->where('vendor_id', $vendor->id)->where('is_deleted', false)->orderByDesc('id')->limit(15)->get(),
            'requests' => PurchaseRequest::query()->forCompany($cid)->where('vendor_id', $vendor->id)->with('items')->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function updateSupplier(Request $request, string $id)
    {
        $vendor = $this->vendor($id);
        $data = $this->supplierData($request);

        $vendor->fill(['name' => $data['name'], 'phone' => $data['phone'] ?? '', 'address1' => $data['address'] ?? '', 'private_notes' => $data['products_supplied'] ?? ''])->save();

        // Update the primary contact only; other contacts are left untouched.
        $contact = $vendor->contacts()->orderByDesc('is_primary')->first();

        if ($contact) {
            $contact->fill([
                'first_name' => $data['contact_first_name'] ?? '',
                'last_name' => $data['contact_last_name'] ?? '',
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
            ])->save();
        }

        AuditLogger::log($this->companyId(), 'supplier.updated', $vendor, ['name' => $vendor->name], $this->user()->id);

        return $this->done('Supplier updated.');
    }

    private function supplierData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'contact_first_name' => 'nullable|string|max:100',
            'contact_last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:191',
            'products_supplied' => 'nullable|string|max:2000',
        ]);
    }

    /* -------------------------- Purchase requests -------------------------- */

    public function requests(Request $request)
    {
        $this->ensure('purchase_requests.create', 'purchase_requests.approve', 'purchase_requests.receive');
        $request->validate(['status' => ['nullable', Rule::in(array_keys(PurchaseRequest::STATUSES))]]);

        $query = PurchaseRequest::query()->forCompany($this->companyId())->with(['items', 'vendor', 'requester']);

        // Requesters without approve/receive rights only see their own requests.
        if (! $this->can('purchase_requests.approve') && ! $this->can('purchase_requests.receive')) {
            $query->where('user_id', $this->user()->id);
        }

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }

        return view('ops::procurement.requests', ['requests' => $query->orderByRaw("status = 'pending' DESC")->orderByDesc('id')->paginate(25)->withQueryString()]);
    }

    public function createRequest(Request $request)
    {
        $preselect = null;

        if ($request->filled('product')) {
            $pid = $this->decodePrimaryKey($request->input('product'), true);
            $preselect = is_numeric($pid) ? Product::query()->where('company_id', $this->companyId())->find($pid) : null;
        }

        return view('ops::procurement.request_form', [
            'products' => Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('product_key')->get(['id', 'product_key', 'notes', 'cost', 'in_stock_quantity', 'vendor_id']),
            'vendors' => Vendor::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('name')->get(['id', 'name']),
            'preselect' => $preselect,
        ]);
    }

    public function storeRequest(Request $request, PurchaseRequestService $service)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PurchaseRequest::TYPES))],
            'vendor' => 'nullable|string|max:40',
            'justification' => 'required|string|min:5|max:2000',
            'items' => 'required|array|min:1|max:30',
            'items.*.product' => 'nullable|string|max:40',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.quantity' => 'nullable|integer|min:0|max:100000',
            'items.*.unit_cost' => 'nullable|numeric|min:0|max:100000000',
        ]);

        $items = [];

        foreach ($data['items'] as $row) {
            $productId = ! empty($row['product']) ? $this->decodePrimaryKey($row['product'], true) : null;
            $product = is_numeric($productId) ? Product::query()->where('company_id', $this->companyId())->find($productId) : null;

            $items[] = [
                'product_id' => $product?->id,
                'description' => trim((string) ($row['description'] ?? '')) ?: ($product ? ($product->notes ?: $product->product_key) : ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'unit_cost' => isset($row['unit_cost']) && $row['unit_cost'] !== '' ? (float) $row['unit_cost'] : (float) ($product->cost ?? 0),
            ];
        }

        $vendorId = ! empty($data['vendor']) ? $this->vendor($data['vendor'])->id : null;

        $pr = $service->create($this->company(), $this->user(), [
            'type' => $data['type'],
            'vendor_id' => $vendorId,
            'justification' => $data['justification'],
            'items' => $items,
        ]);

        return redirect()->route('ops.purchase_requests.show', $pr->hashed_id)->with('ops_success', "Purchase request {$pr->request_number} submitted for approval.");
    }

    public function showRequest(string $id)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id)->load(['items.product', 'vendor', 'requester', 'approver', 'purchaseOrder', 'expense']);

        $canSee = $this->can('purchase_requests.approve') || $this->can('purchase_requests.receive') || (int) $pr->user_id === (int) $this->user()->id;
        abort_unless($canSee, 403);

        return view('ops::procurement.request_show', [
            'pr' => $pr,
            'categories' => ExpenseCategory::query()->where('company_id', $this->companyId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function decide(Request $request, string $id, PurchaseRequestService $service)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'notes' => 'nullable|string|max:1000']);

        if ($data['decision'] === 'reject' && empty($data['notes'])) {
            return back()->withErrors(['notes' => 'Give a reason when rejecting a request.']);
        }

        $service->decide($pr, $this->user(), $data['decision'] === 'approve', $data['notes'] ?? null);

        return $this->done('Purchase request ' . ($data['decision'] === 'approve' ? 'approved.' : 'rejected.'));
    }

    public function order(Request $request, string $id, PurchaseRequestService $service)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id);

        if ($request->filled('vendor') && ! $pr->vendor_id) {
            $pr->vendor_id = $this->vendor($request->input('vendor'))->id;
            $pr->save();
        }

        $po = $service->createPurchaseOrder($pr, $this->user());

        return $this->done("Purchase order {$po->number} created in Invoice Ninja for {$pr->vendor->name}.");
    }

    public function receive(Request $request, string $id, PurchaseRequestService $service)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id);
        $data = $request->validate(['received' => 'required|array', 'received.*' => 'nullable|integer|min:0|max:100000']);

        $service->receive($pr, $this->user(), array_map('intval', array_filter($data['received'], fn ($v) => $v !== null && $v !== '')));

        return $this->done('Stock received and inventory updated.');
    }

    public function expense(Request $request, string $id, PurchaseRequestService $service)
    {
        $pr = PurchaseRequest::forCompanyOrFail($this->companyId(), $id);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0|max:100000000',
            'date' => 'required|date',
            'category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $this->companyId())],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        $expense = $service->recordExpense($pr, $this->user(), $data);

        return $this->done("Supplier invoice recorded as expense {$expense->number} in Invoice Ninja.");
    }

    public function purchaseOrders()
    {
        return view('ops::procurement.purchase_orders', [
            'orders' => PurchaseOrder::query()->where('company_id', $this->companyId())->where('is_deleted', false)->with('vendor')->orderByDesc('id')->paginate(25),
            'linked' => PurchaseRequest::query()->forCompany($this->companyId())->whereNotNull('purchase_order_id')->pluck('request_number', 'purchase_order_id'),
        ]);
    }
}
