<?php

namespace App\Services\Ops;

use App\Events\Invoice\InvoiceWasCreated;
use App\Factory\InvoiceFactory;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Models\Ops\RepairJob;
use App\Repositories\InvoiceRepository;
use App\Utils\Ninja;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Module 3: Repair Completed -> Completion Verified -> Invoice Generated -> Customer Notified.
 *
 * Invoices are produced by the existing InvoiceRepository (calc engine,
 * numbering, invitations) and emailed with the existing invoice mailer, so
 * payment processing, reminders, receipts and status updates stay native.
 *
 * Billing rules:
 *  - Paid jobs bill the line items of every customer-approved quote linked to
 *    the job (initial + approved revisions).
 *  - Parts used beyond the approved quantities are only billed when the
 *    accountant explicitly opts in (they were never approved by the customer).
 *  - Warranty jobs bill covered parts / labor at zero; when nothing is
 *    chargeable no invoice is raised at all.
 *  - Stock is NOT adjusted again here: parts were deducted when used.
 */
class InvoiceGenerator
{
    public function __construct(private WarrantyService $warranties) {}

    /**
     * True when a quote belongs to a repair job. Such quotes must not be
     * auto-converted on approval (Invoice Ninja's auto_convert_quote), because
     * the invoice is raised after verified completion and parts are already
     * deducted from stock at the time of use.
     */
    public static function defersInvoicing(Quote $quote): bool
    {
        if (! config('pcfix.enabled') || ! $quote->id) {
            return false;
        }

        try {
            return \App\Models\Ops\RepairJobQuote::query()->where('quote_id', $quote->id)->exists();
        } catch (\Illuminate\Database\QueryException $e) {
            return false; // module tables not migrated yet
        }
    }

    /** Approved / converted quotes linked to the job. */
    public function approvedQuotes(RepairJob $job)
    {
        return $job->linkedQuotes()->filter(fn (Quote $q) => in_array($q->status_id, [Quote::STATUS_APPROVED, Quote::STATUS_CONVERTED]) && ! $q->is_deleted)->values();
    }

    /**
     * Parts used on the job that exceed the quantities on approved quotes.
     *
     * @return array<string, array{product_key:string, notes:string, quantity:int, unit_price:float}>
     */
    public function unquotedParts(RepairJob $job): array
    {
        if ($job->is_warranty_job) {
            return [];
        }

        $quoted = [];

        foreach ($this->approvedQuotes($job) as $quote) {
            foreach ((array) $quote->line_items as $item) {
                $key = strtolower((string) $item->product_key);
                $quoted[$key] = ($quoted[$key] ?? 0) + (float) $item->quantity;
            }
        }

        $extra = [];

        foreach ($job->parts()->with('product')->get()->groupBy('product_id') as $parts) {
            $product = $parts->first()->product;
            $key = strtolower($product->product_key);
            $used = (int) $parts->sum('quantity');
            $diff = $used - (int) floor($quoted[$key] ?? 0);

            if ($diff > 0) {
                $extra[$key] = [
                    'product_key' => $product->product_key,
                    'notes' => ($product->notes ?: $product->product_key),
                    'quantity' => $diff,
                    'unit_price' => (float) $parts->first()->unit_price,
                ];
            }
        }

        return $extra;
    }

    /**
     * Verify completion and generate the invoice in one step (the TO-BE
     * process makes invoice generation automatic upon verification).
     */
    public function verifyAndGenerate(RepairJob $job, User $by, bool $includeUnquotedParts = false, bool $send = true): ?Invoice
    {
        $result = DB::transaction(function () use ($job, $by, $includeUnquotedParts) {
            /** @var RepairJob $locked */
            $locked = RepairJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['status' => 'Only completed jobs can be invoiced.']);
            }

            if ($locked->invoice_id || $locked->completion_verified_at) {
                throw ValidationException::withMessages(['invoice' => 'This job has already been verified / invoiced.']);
            }

            [$lines, $sourceQuotes, $alreadyInvoiced] = $this->buildLines($locked, $includeUnquotedParts);

            $total = collect($lines)->sum(fn ($l) => $l['cost'] * $l['quantity']);

            $locked->completion_verified_at = now();
            $locked->completion_verified_by = $by->id;

            $invoice = null;
            $created = false;

            if (count($lines) === 0 && $alreadyInvoiced) {
                // Invoice Ninja's "auto convert quote" already raised the invoice at approval.
                $invoice = $alreadyInvoiced;
            } elseif (count($lines) === 0 || ($locked->is_warranty_job && $total <= 0)) {
                if (! $locked->is_warranty_job) {
                    throw ValidationException::withMessages(['invoice' => 'Nothing to bill: the job has no approved quotation. Issue and get a quotation approved first.']);
                }
            } else {
                $invoice = $this->createInvoice($locked, $by, $lines);
                $created = true;

                foreach ($sourceQuotes as $quote) {
                    $quote->invoice_id = $invoice->id;
                    $quote->status_id = Quote::STATUS_CONVERTED;
                    $quote->saveQuietly();
                }
            }

            $locked->invoice_id = $invoice?->id;
            $locked->save();

            app(RepairJobService::class)->log(
                $locked,
                'billing',
                null,
                null,
                $invoice ? "Completion verified. Invoice {$invoice->number} generated." : 'Completion verified. No charge - covered by warranty.',
                true,
                $by->id
            );

            if ($locked->is_warranty_job && $locked->warrantyClaim) {
                $claim = $locked->warrantyClaim;
                $claim->status = 'completed';
                $claim->save();
                $this->warranties->refreshStatus($claim->warranty);
            } elseif (! $locked->is_warranty_job && ! $locked->warranty) {
                $this->warranties->createForJob($locked, $invoice, $by);
            }

            AuditLogger::log($locked->company_id, 'repair_job.invoiced', $locked, ['invoice_id' => $invoice?->id, 'total' => round($total, 2), 'include_unquoted_parts' => $includeUnquotedParts], $by->id);

            return [$locked, $invoice, $created];
        });

        [$locked, $invoice, $created] = $result;
        $job->setRawAttributes($locked->getAttributes(), true);

        if ($invoice && $created && $send) {
            try {
                $invoice->service()->sendEmail();
            } catch (\Throwable $e) {
                Log::warning('PCFix invoice email failed: ' . $e->getMessage());
            }
        }

        return $invoice;
    }

    /**
     * @return array{0: array<int, array>, 1: \Illuminate\Support\Collection, 2: ?Invoice}
     */
    private function buildLines(RepairJob $job, bool $includeUnquotedParts): array
    {
        $lines = [];
        $sourceQuotes = collect();
        $alreadyInvoiced = null;

        if ($job->is_warranty_job) {
            $warranty = $job->warrantyClaim?->warranty;

            foreach ($job->parts()->with('product')->get() as $part) {
                $covered = $part->is_warranty_covered;
                $lines[] = QuoteBuilder::line(
                    $part->product->product_key,
                    ($part->product->notes ?: $part->product->product_key) . ($covered ? " (covered by warranty {$warranty?->warranty_number})" : ' (not covered by warranty)'),
                    $covered ? 0 : (float) $part->unit_price,
                    $part->quantity
                );
            }

            $lines[] = QuoteBuilder::line(
                config('pcfix.defaults.labor_product_key'),
                'Warranty repair labor' . ($warranty?->coversLabor() ? " (covered by warranty {$warranty->warranty_number})" : ''),
                $warranty?->coversLabor() ? 0 : (float) config('pcfix.defaults.labor_rate'),
                1,
                '2'
            );

            return [$lines, $sourceQuotes, null];
        }

        foreach ($this->approvedQuotes($job) as $quote) {
            if ($quote->invoice_id) {
                $alreadyInvoiced ??= Invoice::withTrashed()->find($quote->invoice_id);
                continue;
            }

            foreach ((array) $quote->line_items as $item) {
                $lines[] = (array) $item;
            }

            $sourceQuotes->push($quote);
        }

        if ($includeUnquotedParts) {
            foreach ($this->unquotedParts($job) as $extra) {
                $lines[] = QuoteBuilder::line($extra['product_key'], $extra['notes'] . ' (additional part used during repair)', $extra['unit_price'], $extra['quantity']);
            }
        }

        return [$lines, $sourceQuotes, $alreadyInvoiced];
    }

    private function createInvoice(RepairJob $job, User $by, array $lines): Invoice
    {
        $device = $job->device;

        $notes = implode("\n", array_filter([
            "Repair Job: {$job->job_number}",
            $job->serviceRequest ? "Service Request: {$job->serviceRequest->request_number}" : null,
            $device ? 'Device: ' . $device->label() . ($device->serial_number ? " (S/N {$device->serial_number})" : '') : null,
            'Service completed: ' . optional($job->completed_at)->format('Y-m-d'),
        ]));

        $invoice = app(InvoiceRepository::class)->save([
            'client_id' => $job->client_id,
            'line_items' => array_values($lines),
            'public_notes' => $notes,
            'date' => now()->format('Y-m-d'),
        ], InvoiceFactory::create($job->company_id, $by->id));

        $invoice = $invoice->service()->fillDefaults()->save();
        $invoice = $invoice->service()->markSent()->save();

        event(new InvoiceWasCreated($invoice, $invoice->company, Ninja::eventVars($by->id)));

        return $invoice->fresh();
    }
}
