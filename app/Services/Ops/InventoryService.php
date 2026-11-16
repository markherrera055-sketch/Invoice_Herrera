<?php

namespace App\Services\Ops;

use App\Models\Company;
use App\Models\Ops\InventoryMovement;
use App\Models\Ops\PurchaseRequest;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock ledger on top of Invoice Ninja products (P9).
 *
 * products.in_stock_quantity stays the single source of truth for on-hand
 * quantity (so the existing admin app and stock notifications keep working);
 * every change made through this module is written to inventory_movements
 * inside the same transaction with a row lock on the product.
 */
class InventoryService
{
    /**
     * Apply a signed stock change. Must be called inside or outside a
     * transaction; it opens its own (nested transactions use savepoints).
     */
    public function move(Product $product, int $quantity, string $type, ?int $user_id = null, ?string $notes = null, ?string $ref_type = null, ?int $ref_id = null, ?float $unit_cost = null, bool $allowNegative = false): InventoryMovement
    {
        if ($quantity === 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must not be zero.']);
        }

        return DB::transaction(function () use ($product, $quantity, $type, $user_id, $notes, $ref_type, $ref_id, $unit_cost, $allowNegative) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $newBalance = (int) $locked->in_stock_quantity + $quantity;

            if ($newBalance < 0 && ! $allowNegative) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock for {$locked->product_key}: {$locked->in_stock_quantity} available, " . abs($quantity) . ' requested.',
                ]);
            }

            $locked->in_stock_quantity = $newBalance;
            $locked->saveQuietly();

            $m = new InventoryMovement();
            $m->company_id = $locked->company_id;
            $m->product_id = $locked->id;
            $m->user_id = $user_id;
            $m->type = $type;
            $m->quantity = $quantity;
            $m->balance_after = $newBalance;
            $m->unit_cost = $unit_cost ?? (float) $locked->cost;
            $m->reference_type = $ref_type;
            $m->reference_id = $ref_id;
            $m->notes = $notes ? mb_substr($notes, 0, 500) : null;
            $m->save();

            $product->in_stock_quantity = $newBalance;

            if ($quantity < 0) {
                DB::afterCommit(fn () => $this->checkLowStock($locked->fresh()));
            }

            return $m;
        });
    }

    /** Set stock to an exact counted quantity (physical count adjustment). */
    public function setCount(Product $product, int $counted, int $user_id, string $notes): ?InventoryMovement
    {
        $delta = $counted - (int) $product->fresh()->in_stock_quantity;

        return $delta === 0 ? null : $this->move($product, $delta, 'adjustment', $user_id, $notes, allowNegative: false);
    }

    public function isLow(Product $product): bool
    {
        return $product->track_stock && $product->in_stock_quantity <= (int) $product->stock_notification_threshold;
    }

    public function lowStockQuery(int $company_id)
    {
        return Product::query()
            ->where('company_id', $company_id)
            ->where('is_deleted', false)
            ->where('track_stock', true)
            ->whereColumn('in_stock_quantity', '<=', 'stock_notification_threshold');
    }

    /**
     * Low stock -> notify inventory staff and raise a draft purchase request
     * (one open request per product at a time).
     */
    public function checkLowStock(Product $product): void
    {
        if (! $this->isLow($product)) {
            return;
        }

        $company = Company::find($product->company_id);

        $alreadyRequested = PurchaseRequest::query()
            ->forCompany($product->company_id)
            ->whereIn('status', ['pending', 'approved', 'ordered'])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        if (! $alreadyRequested) {
            $reorder = max(1, ((int) $product->stock_notification_threshold * 2) - (int) $product->in_stock_quantity);

            app(PurchaseRequestService::class)->create($company, null, [
                'type' => 'spare_parts',
                'vendor_id' => $product->vendor_id,
                'justification' => "Automatic low-stock request: {$product->product_key} has {$product->in_stock_quantity} left (minimum {$product->stock_notification_threshold}).",
                'items' => [[
                    'product_id' => $product->id,
                    'description' => $product->notes ?: $product->product_key,
                    'quantity' => $reorder,
                    'unit_cost' => (float) $product->cost,
                ]],
            ], 'low_stock');
        }

        OpsNotifier::staff(
            $company,
            'inventory.manage',
            'low_stock',
            $product->id . ':' . now()->format('Y-m-d'),
            "Low stock: {$product->product_key}",
            "{$product->product_key} ({$product->notes}) is at {$product->in_stock_quantity} units, minimum is {$product->stock_notification_threshold}.",
            route('ops.inventory.show', $product->hashed_id),
        );
    }
}
