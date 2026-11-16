<?php

namespace App\Services\Ops;

use App\Factory\ExpenseFactory;
use App\Factory\PurchaseOrderFactory;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Ops\OpsSequence;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\PurchaseRequestItem;
use App\Repositories\ExpenseRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 5C: Low Stock / Need -> Purchase Request -> Manager Approval ->
 * Purchase Order (existing IN module) -> Stock Received -> Inventory Updated
 * -> Supplier expense recorded in Invoice Ninja (P8).
 */
class PurchaseRequestService
{
    public function __construct(private InventoryService $inventory) {}

    public function create(Company $company, ?User $by, array $data, string $source = 'manual'): PurchaseRequest
    {
        $items = array_values(array_filter($data['items'] ?? [], fn ($i) => ! empty($i['description']) && (int) ($i['quantity'] ?? 0) > 0));

        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Add at least one item with a quantity.']);
        }

        $vendorId = $data['vendor_id'] ?? null;

        if ($vendorId && ! Vendor::query()->where('company_id', $company->id)->whereKey($vendorId)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Invalid supplier.']);
        }

        $pr = DB::transaction(function () use ($company, $by, $data, $items, $vendorId, $source) {
            $pr = new PurchaseRequest();
            $pr->company_id = $company->id;
            $pr->user_id = $by?->id;
            $pr->vendor_id = $vendorId;
            $pr->request_number = OpsSequence::next($company->id, 'PR');
            $pr->type = $data['type'] ?? 'spare_parts';
            $pr->justification = $data['justification'] ?? null;
            $pr->source = $source;
            $pr->status = 'pending';
            $pr->save();

            foreach ($items as $item) {
                $productId = $item['product_id'] ?? null;

                if ($productId && ! Product::query()->where('company_id', $company->id)->whereKey($productId)->exists()) {
                    throw ValidationException::withMessages(['items' => 'Invalid inventory item.']);
                }

                $pr->items()->create([
                    'product_id' => $productId ?: null,
                    'description' => mb_substr($item['description'], 0, 255),
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => max(0, (float) ($item['unit_cost'] ?? 0)),
                ]);
            }

            AuditLogger::log($company->id, 'purchase_request.created', $pr, ['source' => $source, 'type' => $pr->type], $by?->id);

            return $pr;
        });

        OpsNotifier::staff($company, 'purchase_requests.approve', 'purchase_request', 'p' . $pr->id, "Purchase request {$pr->request_number} needs approval", (PurchaseRequest::TYPES[$pr->type] ?? $pr->type) . ': ' . ($pr->justification ?? '') . ' Estimated total: ' . number_format($pr->load('items')->total(), 2), route('ops.purchase_requests.show', $pr->hashed_id));

        return $pr;
    }

    public function decide(PurchaseRequest $pr, User $by, bool $approve, ?string $notes): PurchaseRequest
    {
        DB::transaction(function () use ($pr, $by, $approve, $notes) {
            $locked = PurchaseRequest::query()->whereKey($pr->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            if ($locked->user_id && (int) $locked->user_id === (int) $by->id && ! OpsAccess::currentCompanyUser($by)?->is_owner) {
                throw ValidationException::withMessages(['status' => 'You cannot approve or reject your own purchase request.']);
            }

            $locked->status = $approve ? 'approved' : 'rejected';
            $locked->approved_by = $by->id;
            $locked->approved_at = now();
            $locked->decision_notes = $notes;
            $locked->save();

            AuditLogger::log($locked->company_id, $approve ? 'purchase_request.approved' : 'purchase_request.rejected', $locked, ['notes' => $notes], $by->id);

            $pr->setRawAttributes($locked->getAttributes(), true);
        });

        $company = Company::find($pr->company_id);
        $title = "Purchase request {$pr->request_number} " . ($approve ? 'approved' : 'rejected');
        $url = route('ops.purchase_requests.show', $pr->hashed_id);

        if ($approve) {
            OpsNotifier::staff($company, 'purchase_requests.receive', 'purchase_request_approved', 'p' . $pr->id, $title, 'Approved by ' . $by->present()->name() . '. Please place the order with the supplier.', $url);
        }

        if ($pr->requester) {
            OpsNotifier::user($company, $pr->requester, 'purchase_request_decided', 'p' . $pr->id, $title, $notes ?: $title, $url);
        }

        return $pr;
    }

    /** Create a draft purchase order in Invoice Ninja's existing Purchase Orders module. */
    public function createPurchaseOrder(PurchaseRequest $pr, User $by): PurchaseOrder
    {
        if ($pr->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only approved requests can be ordered.']);
        }

        if (! $pr->vendor_id) {
            throw ValidationException::withMessages(['vendor_id' => 'Select a supplier for this request first.']);
        }

        return DB::transaction(function () use ($pr, $by) {
            $lines = $pr->items->map(fn (PurchaseRequestItem $i) => QuoteBuilder::line(
                $i->product?->product_key ?? 'ITEM',
                $i->description,
                (float) $i->unit_cost,
                $i->quantity
            ))->all();

            $po = PurchaseOrderFactory::create($pr->company_id, $by->id);
            $po->vendor_id = $pr->vendor_id;

            $po = app(PurchaseOrderRepository::class)->save([
                'vendor_id' => $pr->vendor_id,
                'line_items' => $lines,
                'public_notes' => "Purchase request {$pr->request_number}",
                'date' => now()->format('Y-m-d'),
            ], $po);

            $po = $po->service()->fillDefaults()->applyNumber()->save();

            $pr->purchase_order_id = $po->id;
            $pr->status = 'ordered';
            $pr->save();

            AuditLogger::log($pr->company_id, 'purchase_request.ordered', $pr, ['purchase_order' => $po->number], $by->id);

            return $po;
        });
    }

    /**
     * Receive stock. $quantities = [item_id => qty received now].
     */
    public function receive(PurchaseRequest $pr, User $by, array $quantities): PurchaseRequest
    {
        if (! in_array($pr->status, ['approved', 'ordered'], true)) {
            throw ValidationException::withMessages(['status' => 'Only approved or ordered requests can be received.']);
        }

        DB::transaction(function () use ($pr, $by, $quantities) {
            $any = false;

            foreach ($pr->items()->lockForUpdate()->get() as $item) {
                $qty = (int) ($quantities[$item->id] ?? 0);
                $remaining = $item->quantity - $item->received_quantity;

                if ($qty <= 0) {
                    continue;
                }

                if ($qty > $remaining) {
                    throw ValidationException::withMessages(['quantities' => "Cannot receive more than the {$remaining} outstanding for \"{$item->description}\"."]);
                }

                if ($item->product) {
                    $this->inventory->move($item->product, $qty, 'purchase_receipt', $by->id, "Received on {$pr->request_number}", 'purchase_request', $pr->id, (float) $item->unit_cost);

                    if ((float) $item->unit_cost > 0) {
                        $item->product->cost = $item->unit_cost; // latest purchase cost
                        $item->product->saveQuietly();
                    }
                }

                $item->received_quantity += $qty;
                $item->save();
                $any = true;
            }

            if (! $any) {
                throw ValidationException::withMessages(['quantities' => 'Enter at least one received quantity.']);
            }

            if ($pr->items()->whereColumn('received_quantity', '<', 'quantity')->doesntExist()) {
                $pr->status = 'received';
                $pr->received_at = now();

                if ($pr->purchaseOrder && $pr->purchaseOrder->status_id < PurchaseOrder::STATUS_RECEIVED) {
                    // Stock was already added through the ledger above, so only flag the PO as received.
                    $pr->purchaseOrder->status_id = PurchaseOrder::STATUS_RECEIVED;
                    $pr->purchaseOrder->saveQuietly();
                }
            }

            $pr->save();

            AuditLogger::log($pr->company_id, 'purchase_request.received', $pr, ['quantities' => $quantities], $by->id);
        });

        return $pr->fresh('items');
    }

    /** Record the supplier invoice as an Invoice Ninja expense linked to the vendor. */
    public function recordExpense(PurchaseRequest $pr, User $by, array $data): \App\Models\Expense
    {
        if (! in_array($pr->status, ['ordered', 'received', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'Record the expense after the request is approved.']);
        }

        if ($pr->expense_id) {
            throw ValidationException::withMessages(['expense' => 'An expense is already recorded for this request.']);
        }

        return DB::transaction(function () use ($pr, $by, $data) {
            $expense = app(ExpenseRepository::class)->save([
                'vendor_id' => $pr->vendor_id,
                // Supplier invoices are recorded in the company currency (as StoreExpenseRequest defaults).
                'currency_id' => (string) Company::find($pr->company_id)->settings->currency_id,
                'amount' => round((float) ($data['amount'] ?? $pr->total()), 2),
                'date' => $data['date'] ?? now()->format('Y-m-d'),
                'category_id' => $data['category_id'] ?? null,
                'transaction_reference' => $data['reference'] ?? null,
                'public_notes' => "Supplier invoice for purchase request {$pr->request_number}",
                'private_notes' => $data['notes'] ?? null,
            ], ExpenseFactory::create($pr->company_id, $by->id));

            $pr->expense_id = $expense->id;
            $pr->save();

            AuditLogger::log($pr->company_id, 'purchase_request.expense_recorded', $pr, ['expense_id' => $expense->id, 'amount' => $expense->amount], $by->id);

            return $expense;
        });
    }
}
