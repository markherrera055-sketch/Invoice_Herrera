<?php

namespace App\Services\Ops;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Quote;

/**
 * Presentation helpers for the operations views.
 */
class OpsUi
{
    private const TONES = [
        // repair jobs
        'pending' => 'b-warn', 'awaiting_approval' => 'b-warn', 'approved' => 'b-info', 'assigned' => 'b-info',
        'in_progress' => 'b-info', 'waiting_for_parts' => 'b-serious', 'completed' => 'b-good', 'cancelled' => '',
        // service requests
        'new' => 'b-warn', 'under_review' => 'b-info', 'quoted' => 'b-info', 'rejected' => 'b-bad', 'converted' => 'b-good',
        // warranty
        'active' => 'b-good', 'expiring_soon' => 'b-warn', 'expired' => '', 'claimed' => 'b-info', 'void' => 'b-bad',
        'submitted' => 'b-warn', 'in_repair' => 'b-info',
        // purchase requests
        'ordered' => 'b-info', 'received' => 'b-good',
    ];

    public static function tone(?string $status): string
    {
        return self::TONES[$status] ?? '';
    }

    public static function badge(?string $status, ?string $label = null): string
    {
        $label ??= ucwords(str_replace('_', ' ', (string) $status));

        return '<span class="badge ' . self::tone($status) . '">' . e($label) . '</span>';
    }

    public static function priority(?string $p): string
    {
        return '<span class="badge prio-' . e($p) . '">' . e(ucfirst((string) $p)) . '</span>';
    }

    public static function money($amount, ?int $currency_id = null): string
    {
        static $symbols = [];
        $currency_id ??= (int) (app(\App\Utils\TruthSource::class)->getCompany()?->settings->currency_id ?? 0);

        if (! isset($symbols[$currency_id])) {
            $c = Currency::find($currency_id);
            $symbols[$currency_id] = $c ? ($c->symbol ?: $c->code . ' ') : '';
        }

        $n = (float) $amount;

        return ($n < 0 ? '-' : '') . $symbols[$currency_id] . number_format(abs($n), 2);
    }

    public static function invoiceStatus(Invoice $invoice): string
    {
        if (in_array($invoice->status_id, [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL]) && $invoice->due_date && $invoice->due_date < now()->toDateString()) {
            return '<span class="badge b-bad">Overdue</span>';
        }

        return match ((int) $invoice->status_id) {
            Invoice::STATUS_DRAFT => '<span class="badge">Draft</span>',
            Invoice::STATUS_SENT => '<span class="badge b-warn">Unpaid</span>',
            Invoice::STATUS_PARTIAL => '<span class="badge b-serious">Partially Paid</span>',
            Invoice::STATUS_PAID => '<span class="badge b-good">Paid</span>',
            Invoice::STATUS_CANCELLED => '<span class="badge">Cancelled</span>',
            Invoice::STATUS_REVERSED => '<span class="badge">Reversed</span>',
            default => '<span class="badge">-</span>',
        };
    }

    public static function quoteStatus(?Quote $quote): string
    {
        if (! $quote) {
            return '-';
        }

        return match ((int) $quote->status_id) {
            Quote::STATUS_DRAFT => '<span class="badge">Draft</span>',
            Quote::STATUS_SENT => '<span class="badge b-warn">Awaiting customer</span>',
            Quote::STATUS_APPROVED => '<span class="badge b-good">Approved</span>',
            Quote::STATUS_CONVERTED => '<span class="badge b-good">Invoiced</span>',
            Quote::STATUS_REJECTED => '<span class="badge b-bad">Declined</span>',
            Quote::STATUS_CANCELLED => '<span class="badge">Cancelled</span>',
            default => '<span class="badge">Expired</span>',
        };
    }
}
