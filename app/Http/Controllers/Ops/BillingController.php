<?php

namespace App\Http\Controllers\Ops;

use App\Models\Invoice;
use App\Models\Ops\RepairJob;
use App\Services\Ops\InvoiceGenerator;
use App\Services\Ops\SmsGateway;
use Illuminate\Http\Request;

/**
 * Module 3: ready-to-invoice queue + payment monitoring dashboard.
 * Payment status comes straight from Invoice Ninja's invoice lifecycle.
 */
class BillingController extends OpsController
{
    public function index(Request $request, InvoiceGenerator $billing)
    {
        $request->validate(['bucket' => 'nullable|in:unpaid,partial,paid,overdue', 'q' => 'nullable|string|max:100']);

        $cid = $this->companyId();
        $today = now()->toDateString();

        $ready = RepairJob::query()->forCompany($cid)->where('status', 'completed')->whereNull('completion_verified_at')
            ->with(['client', 'device', 'technician', 'parts.product'])->orderBy('completed_at')->get()
            ->map(function (RepairJob $job) use ($billing) {
                $quotes = $billing->approvedQuotes($job);

                return [
                    'job' => $job,
                    'quoted' => round($quotes->filter(fn ($q) => ! $q->invoice_id)->sum('amount'), 2),
                    'quotes' => $quotes,
                    'unquoted' => $billing->unquotedParts($job),
                ];
            });

        $invoices = Invoice::query()->where('company_id', $cid)->where('is_deleted', false)->with('client');

        $bucket = $request->input('bucket', 'unpaid');

        match ($bucket) {
            'paid' => $invoices->where('status_id', Invoice::STATUS_PAID),
            'partial' => $invoices->where('status_id', Invoice::STATUS_PARTIAL),
            'overdue' => $invoices->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])->where('due_date', '<', $today),
            default => $invoices->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL]),
        };

        if ($q = $request->input('q')) {
            $invoices->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%")));
        }

        $base = Invoice::query()->where('company_id', $cid)->where('is_deleted', false);

        $summary = [
            'unpaid' => (clone $base)->whereIn('status_id', [2, 3])->selectRaw('COUNT(*) c, COALESCE(SUM(balance),0) s')->first(),
            'partial' => (clone $base)->where('status_id', 3)->selectRaw('COUNT(*) c, COALESCE(SUM(balance),0) s')->first(),
            'overdue' => (clone $base)->whereIn('status_id', [2, 3])->where('due_date', '<', $today)->selectRaw('COUNT(*) c, COALESCE(SUM(balance),0) s')->first(),
            'paid' => (clone $base)->where('status_id', 4)->where('updated_at', '>=', now()->startOfMonth())->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) s')->first(),
        ];

        $repairInvoiceIds = RepairJob::query()->forCompany($cid)->whereNotNull('invoice_id')->pluck('job_number', 'invoice_id');

        $settings = $this->company()->settings;

        return view('ops::billing.index', [
            'ready' => $ready,
            'invoices' => $invoices->orderByRaw('due_date IS NULL, due_date')->paginate(25)->withQueryString(),
            'bucket' => $bucket,
            'summary' => $summary,
            'repairInvoiceIds' => $repairInvoiceIds,
            'automation' => [
                'email_reminders' => (bool) ($settings->enable_reminder1 ?? false) || (bool) ($settings->enable_reminder_endless ?? false),
                'online_receipts' => (bool) ($settings->client_online_payment_notification ?? false),
                'manual_receipts' => (bool) ($settings->client_manual_payment_notification ?? false),
                'sms' => SmsGateway::isConfigured(),
            ],
        ]);
    }

    public function generate(Request $request, string $id, InvoiceGenerator $billing)
    {
        $job = RepairJob::forCompanyOrFail($this->companyId(), $id);
        $request->validate(['include_unquoted_parts' => 'nullable|boolean', 'send' => 'nullable|boolean']);

        $invoice = $billing->verifyAndGenerate($job, $this->user(), $request->boolean('include_unquoted_parts'), $request->boolean('send', true));

        return $this->done($invoice
            ? "Completion verified. Invoice {$invoice->number} generated" . ($request->boolean('send', true) ? ' and emailed to the customer.' : '.')
            : 'Completion verified. Warranty repair - no charge, no invoice raised.');
    }
}
