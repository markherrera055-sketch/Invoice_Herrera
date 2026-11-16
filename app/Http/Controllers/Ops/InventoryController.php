<?php

namespace App\Http\Controllers\Ops;

use App\Factory\ProductFactory;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Ops\InventoryMovement;
use App\Repositories\ProductRepository;
use App\Services\Ops\AuditLogger;
use App\Services\Ops\InventoryService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 5A: inventory built on Invoice Ninja products (no parallel item table).
 */
class InventoryController extends OpsController
{
    use MakesHash;
    use Concerns\StreamsCsv;

    /**
     * Inventory reports (CSV):
     *  - report=stock     : current stock levels, status and valuation (default)
     *  - report=movements : stock movement ledger for a date range
     */
    public function export(Request $request)
    {
        $data = $request->validate([
            'report' => 'nullable|in:stock,movements',
            'type' => ['nullable', Rule::in(array_keys(InventoryMovement::TYPES))],
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if (($data['report'] ?? 'stock') === 'movements') {
            $query = InventoryMovement::query()->forCompany($this->companyId())->with(['product', 'user'])->orderBy('id');

            if (! empty($data['type'])) {
                $query->where('type', $data['type']);
            }

            if (! empty($data['from'])) {
                $query->where('created_at', '>=', $data['from']);
            }

            if (! empty($data['to'])) {
                $query->where('created_at', '<=', $data['to'] . ' 23:59:59');
            }

            $rows = [['Date', 'SKU', 'Item', 'Movement', 'Quantity', 'Balance after', 'Unit cost', 'Reference', 'Notes', 'By']];

            foreach ($query->cursor() as $m) {
                $rows[] = [
                    $m->created_at->format('Y-m-d H:i'), $m->product?->product_key, $m->product?->notes, $m->typeLabel(),
                    (int) $m->quantity, (int) $m->balance_after, round((float) $m->unit_cost, 2),
                    $m->reference_type ? $m->reference_type . ' #' . $m->reference_id : '', $m->notes, $m->user?->present()->name() ?? 'System',
                ];
            }

            return $this->csv('pcfix-stock-movements-' . now()->format('Ymd') . '.csv', $rows);
        }

        $rows = [['SKU', 'Item', 'Category', 'Storage location', 'Supplier', 'On hand', 'Minimum', 'Status', 'Unit cost', 'Selling price', 'Stock value (cost)']];

        $items = Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->where('track_stock', true)->with('vendor')->orderBy('product_key')->get();

        foreach ($items as $p) {
            $qty = (int) $p->in_stock_quantity;
            $rows[] = [
                $p->product_key, $p->notes, $p->category, $p->storage_location, $p->vendor?->name,
                $qty, (int) $p->stock_notification_threshold,
                $qty <= 0 ? 'Out of stock' : ($qty <= (int) $p->stock_notification_threshold ? 'Low' : 'OK'),
                round((float) $p->cost, 2), round((float) $p->price, 2), round(max(0, $qty) * (float) $p->cost, 2),
            ];
        }

        $rows[] = [];
        $rows[] = ['Totals', '', '', '', '', $items->sum(fn ($p) => max(0, (int) $p->in_stock_quantity)), '', '', '', '', round($items->sum(fn ($p) => max(0, (int) $p->in_stock_quantity) * (float) $p->cost), 2)];

        return $this->csv('pcfix-stock-levels-' . now()->format('Ymd') . '.csv', $rows);
    }

    private function product(string $id): Product
    {
        $pid = $this->decodePrimaryKey($id, true);
        abort_unless(is_numeric($pid), 404);

        return Product::query()->where('company_id', $this->companyId())->whereKey($pid)->firstOrFail();
    }

    public function index(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'scope' => 'nullable|in:tracked,all']);

        $query = Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->with('vendor');

        if ($request->input('scope', 'tracked') === 'tracked') {
            $query->where('track_stock', true);
        }

        if ($q = $request->input('q')) {
            $query->where(fn ($w) => $w->where('product_key', 'like', "%{$q}%")->orWhere('notes', 'like', "%{$q}%"));
        }

        if ($c = $request->input('category')) {
            $query->where('category', $c);
        }

        return view('ops::inventory.index', [
            'items' => $query->orderBy('product_key')->paginate(30)->withQueryString(),
            'categories' => Product::query()->where('company_id', $this->companyId())->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'untracked' => Product::query()->where('company_id', $this->companyId())->where('is_deleted', false)->where('track_stock', false)->count(),
        ]);
    }

    public function create()
    {
        return view('ops::inventory.form', ['item' => null, 'vendors' => $this->vendors()]);
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $data = $this->validated($request);

        $product = app(ProductRepository::class)->save([
            'product_key' => $data['product_key'],
            'notes' => $data['notes'] ?? '',
            'cost' => $data['cost'],
            'price' => $data['price'],
            'stock_notification' => true,
            'stock_notification_threshold' => $data['minimum'],
        ], ProductFactory::create($this->companyId(), $this->user()->id));

        $product->forceFill([
            'track_stock' => true,
            'category' => $data['category'] ?? null,
            'storage_location' => $data['storage_location'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'in_stock_quantity' => 0,
        ])->saveQuietly();

        if (($data['opening_stock'] ?? 0) > 0) {
            $inventory->move($product, (int) $data['opening_stock'], 'stock_in', $this->user()->id, 'Opening stock');
        }

        AuditLogger::log($this->companyId(), 'inventory.item_created', $product, ['sku' => $product->product_key], $this->user()->id);

        return redirect()->route('ops.inventory.show', $product->hashed_id)->with('ops_success', 'Inventory item created.');
    }

    public function show(string $id)
    {
        $item = $this->product($id)->load('vendor');

        return view('ops::inventory.show', [
            'item' => $item,
            'movements' => InventoryMovement::query()->forCompany($this->companyId())->where('product_id', $item->id)->with('user')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function edit(string $id)
    {
        return view('ops::inventory.form', ['item' => $this->product($id), 'vendors' => $this->vendors()]);
    }

    public function update(Request $request, string $id)
    {
        $item = $this->product($id);
        $data = $this->validated($request, $item);
        $before = $item->only(['product_key', 'cost', 'price', 'stock_notification_threshold', 'vendor_id']);

        $item->forceFill([
            'product_key' => $data['product_key'],
            'notes' => $data['notes'] ?? '',
            'cost' => $data['cost'],
            'price' => $data['price'],
            'stock_notification' => true,
            'stock_notification_threshold' => $data['minimum'],
            'track_stock' => true,
            'category' => $data['category'] ?? null,
            'storage_location' => $data['storage_location'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
        ])->save();

        AuditLogger::log($this->companyId(), 'inventory.item_updated', $item, ['before' => $before], $this->user()->id);

        return redirect()->route('ops.inventory.show', $item->hashed_id)->with('ops_success', 'Item updated. Stock quantity can only change through stock movements.');
    }

    public function adjust(Request $request, string $id, InventoryService $inventory)
    {
        $item = $this->product($id);

        $data = $request->validate([
            'mode' => 'required|in:stock_in,stock_out,count',
            'quantity' => 'required|integer|min:0|max:100000',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'required|string|min:3|max:500',
        ]);

        if (! $item->track_stock) {
            $item->forceFill(['track_stock' => true])->saveQuietly();
        }

        $qty = (int) $data['quantity'];

        $movement = match ($data['mode']) {
            'stock_in' => $qty > 0 ? $inventory->move($item, $qty, 'stock_in', $this->user()->id, $data['notes'], unit_cost: isset($data['unit_cost']) ? (float) $data['unit_cost'] : null) : null,
            'stock_out' => $qty > 0 ? $inventory->move($item, -$qty, 'stock_out', $this->user()->id, $data['notes']) : null,
            'count' => $inventory->setCount($item, $qty, $this->user()->id, 'Physical count: ' . $data['notes']),
        };

        AuditLogger::log($this->companyId(), 'inventory.adjusted', $item, ['mode' => $data['mode'], 'quantity' => $qty, 'balance' => $item->fresh()->in_stock_quantity], $this->user()->id);

        return $this->done($movement ? "Stock updated. New balance: {$movement->balance_after}." : 'No change: counted quantity matches the system.');
    }

    public function archive(string $id)
    {
        $item = $this->product($id);
        $item->forceFill(['track_stock' => false])->saveQuietly();

        AuditLogger::log($this->companyId(), 'inventory.item_archived', $item, [], $this->user()->id);

        return redirect()->route('ops.inventory.index')->with('ops_success', "{$item->product_key} removed from stock tracking (product kept in Invoice Ninja).");
    }

    public function movements(Request $request)
    {
        $request->validate(['type' => ['nullable', Rule::in(array_keys(InventoryMovement::TYPES))], 'from' => 'nullable|date', 'to' => 'nullable|date']);

        $query = InventoryMovement::query()->forCompany($this->companyId())->with(['product', 'user']);

        if ($t = $request->input('type')) {
            $query->where('type', $t);
        }

        if ($f = $request->input('from')) {
            $query->where('created_at', '>=', $f);
        }

        if ($t = $request->input('to')) {
            $query->where('created_at', '<=', $t . ' 23:59:59');
        }

        return view('ops::inventory.movements', ['movements' => $query->orderByDesc('id')->paginate(40)->withQueryString()]);
    }

    public function lowStock(InventoryService $inventory)
    {
        return view('ops::inventory.low', [
            'items' => $inventory->lowStockQuery($this->companyId())->with('vendor')->orderByRaw('in_stock_quantity - stock_notification_threshold')->get(),
        ]);
    }

    private function vendors()
    {
        return Vendor::query()->where('company_id', $this->companyId())->where('is_deleted', false)->orderBy('name')->get(['id', 'name']);
    }

    private function validated(Request $request, ?Product $item = null): array
    {
        $data = $request->validate([
            'product_key' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'product_key')->where('company_id', $this->companyId())->where('is_deleted', false)->ignore($item?->id),
            ],
            'notes' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'storage_location' => 'nullable|string|max:150',
            'cost' => 'required|numeric|min:0|max:100000000',
            'price' => 'required|numeric|min:0|max:100000000',
            'minimum' => 'required|integer|min:0|max:100000',
            'vendor' => 'nullable|string|max:40',
            'opening_stock' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['vendor_id'] = null;

        if (! empty($data['vendor'])) {
            $vid = $this->decodePrimaryKey($data['vendor'], true);
            $data['vendor_id'] = Vendor::query()->where('company_id', $this->companyId())->whereKey($vid)->value('id');
        }

        return $data;
    }
}
