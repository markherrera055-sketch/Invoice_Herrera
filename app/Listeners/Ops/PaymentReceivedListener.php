<?php

namespace App\Listeners\Ops;

use App\Libraries\MultiDB;
use App\Models\Ops\RepairJob;
use App\Services\Ops\OpsNotifier;
use App\Services\Ops\RepairJobService;
use Illuminate\Support\Facades\Log;

/**
 * Payment received -> record on the repair job timeline and notify billing.
 *
 * Invoice status, client balance and the customer payment receipt are all
 * handled by Invoice Ninja's existing payment engine; this listener does not
 * duplicate them.
 */
class PaymentReceivedListener
{
    public function handle($event): void
    {
        try {
            MultiDB::setDb($event->company->db);

            $payment = $event->payment->fresh(['invoices']);
            $invoiceIds = $payment->invoices->pluck('id');

            if ($invoiceIds->isEmpty()) {
                return;
            }

            $jobs = RepairJob::query()->forCompany($payment->company_id)->whereIn('invoice_id', $invoiceIds)->get();
            $service = app(RepairJobService::class);

            foreach ($jobs as $job) {
                $invoice = $payment->invoices->firstWhere('id', $job->invoice_id)?->fresh();
                $status = $invoice && (int) $invoice->status_id === \App\Models\Invoice::STATUS_PAID ? 'paid in full' : 'partially paid';

                $service->log($job, 'billing', null, null, "Payment {$payment->number} received ({$payment->amount}). Invoice {$invoice?->number} {$status}.", true, null);

                OpsNotifier::staff(
                    $event->company,
                    'billing.view',
                    'payment_received',
                    'pay' . $payment->id . ':j' . $job->id,
                    "Payment received: {$job->job_number}",
                    "Payment {$payment->number} of {$payment->amount} applied to invoice {$invoice?->number} ({$status}).",
                    route('ops.billing.index'),
                    email: false,
                );
            }
        } catch (\Throwable $e) {
            Log::error('PCFix payment listener failed: ' . $e->getMessage());
        }
    }
}
