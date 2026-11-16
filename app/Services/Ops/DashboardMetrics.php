<?php

namespace App\Services\Ops;

use App\Models\Company;
use App\Models\Ops\InventoryMovement;
use App\Models\Ops\RepairJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 6 analytics. Every figure is computed from live database records.
 *
 * Financial formulas mirror Invoice Ninja's own ChartQueries so numbers match
 * the native dashboard:
 *  - revenue     = SUM(payments.amount - payments.refunded), status 1,4,5,6
 *  - expenses    = expense amount + taxes (exclusive taxes) / amount (inclusive)
 *  - outstanding = SUM(invoices.balance) for sent (2) and partial (3) invoices
 * Figures are reported in the company currency; amounts in other currencies
 * are reported separately (never mixed).
 */
class DashboardMetrics
{
    private int $currency;

    public function __construct(private Company $company, private Carbon $from, private Carbon $to)
    {
        $this->currency = (int) $company->settings->currency_id;
    }

    public static function forRange(Company $company, ?string $from, ?string $to): self
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfYear();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return new self($company, $start, $end);
    }

    public function from(): Carbon
    {
        return $this->from;
    }

    public function to(): Carbon
    {
        return $this->to;
    }

    private function range(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /* ------------------------------ Financial ------------------------------ */

    public function financial(): array
    {
        [$s, $e] = $this->range();
        $cid = $this->company->id;

        $revenueRows = DB::select('
            SELECT payments.currency_id AS currency_id, SUM(payments.amount - payments.refunded) AS amount
            FROM payments JOIN clients ON clients.id = payments.client_id
            WHERE payments.company_id = ? AND payments.is_deleted = 0 AND clients.is_deleted = 0
              AND payments.status_id IN (1,4,5,6) AND payments.date BETWEEN ? AND ?
            GROUP BY payments.currency_id', [$cid, $s, $e]);

        $expenseRows = DB::select('
            SELECT IFNULL(expenses.currency_id, ?) AS currency_id,
              SUM(CASE WHEN expenses.uses_inclusive_taxes = 0 THEN
                    expenses.amount
                    + (COALESCE(expenses.tax_amount1,0) + COALESCE(expenses.tax_amount2,0) + COALESCE(expenses.tax_amount3,0))
                    + ((expenses.amount * COALESCE(expenses.tax_rate1,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate2,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate3,0)/100))
                  ELSE expenses.amount END) AS amount
            FROM expenses
            LEFT JOIN clients ON clients.id = expenses.client_id
            LEFT JOIN vendors ON vendors.id = expenses.vendor_id
            WHERE expenses.is_deleted = 0 AND expenses.company_id = ? AND expenses.date BETWEEN ? AND ?
              AND (clients.id IS NULL OR clients.is_deleted = 0) AND (vendors.id IS NULL OR vendors.is_deleted = 0)
            GROUP BY 1', [$this->currency, $cid, $s, $e]);

        $invoiceBase = DB::table('invoices')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->where('invoices.company_id', $cid)
            ->where('invoices.is_deleted', false)
            ->whereNull('invoices.deleted_at')
            ->where('clients.is_deleted', false);

        $today = now()->toDateString();

        $outstanding = (clone $invoiceBase)->whereIn('invoices.status_id', [2, 3])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(invoices.balance),0) AS amount')->first();

        $overdue = (clone $invoiceBase)->whereIn('invoices.status_id', [2, 3])
            ->where(function ($q) use ($today) {
                $q->where(fn ($q) => $q->whereNotNull('invoices.partial_due_date')->where('invoices.partial', '>', 0)->where('invoices.partial_due_date', '<', $today))
                    ->orWhere(fn ($q) => $q->whereNotNull('invoices.due_date')->where('invoices.due_date', '<', $today));
            })
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(invoices.balance),0) AS amount')->first();

        $inRange = (clone $invoiceBase)->whereBetween('invoices.date', [$s, $e]);

        $pick = fn (array $rows) => (float) (collect($rows)->firstWhere('currency_id', $this->currency)->amount ?? 0);
        $others = fn (array $rows) => collect($rows)->filter(fn ($r) => (int) $r->currency_id !== $this->currency && (float) $r->amount != 0)->count();

        $revenue = round($pick($revenueRows), 2);
        $expenses = round($pick($expenseRows), 2);

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_income' => round($revenue - $expenses, 2),
            'outstanding' => round((float) $outstanding->amount, 2),
            'outstanding_count' => (int) $outstanding->c,
            'overdue' => round((float) $overdue->amount, 2),
            'overdue_count' => (int) $overdue->c,
            'paid_count' => (clone $inRange)->where('invoices.status_id', 4)->count(),
            'unpaid_count' => (clone $inRange)->where('invoices.status_id', 2)->count(),
            'partial_count' => (clone $inRange)->where('invoices.status_id', 3)->count(),
            'invoiced' => round((float) (clone $inRange)->whereIn('invoices.status_id', [2, 3, 4])->sum('invoices.amount'), 2),
            'other_currency_rows' => $others($revenueRows) + $others($expenseRows),
        ];
    }

    /**
     * Revenue trend grouped by day | week | month | year.
     *
     * @return array<int, array{label:string, revenue:float, expenses:float}>
     */
    public function salesTrend(string $grain = 'month'): array
    {
        [$s, $e] = $this->range();
        $cid = $this->company->id;

        $fmt = match ($grain) {
            'day' => '%Y-%m-%d',
            'week' => '%x-W%v',
            'year' => '%Y',
            default => '%Y-%m',
        };

        $rev = collect(DB::select("
            SELECT DATE_FORMAT(payments.date, '{$fmt}') AS k, SUM(payments.amount - payments.refunded) AS amount
            FROM payments JOIN clients ON clients.id = payments.client_id
            WHERE payments.company_id = ? AND payments.is_deleted = 0 AND clients.is_deleted = 0
              AND payments.status_id IN (1,4,5,6) AND payments.date BETWEEN ? AND ? AND payments.currency_id = ?
            GROUP BY k", [$cid, $s, $e, $this->currency]))->pluck('amount', 'k');

        $exp = collect(DB::select("
            SELECT DATE_FORMAT(expenses.date, '{$fmt}') AS k,
              SUM(CASE WHEN expenses.uses_inclusive_taxes = 0 THEN expenses.amount
                + (COALESCE(expenses.tax_amount1,0) + COALESCE(expenses.tax_amount2,0) + COALESCE(expenses.tax_amount3,0))
                + ((expenses.amount * COALESCE(expenses.tax_rate1,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate2,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate3,0)/100))
                ELSE expenses.amount END) AS amount
            FROM expenses
            WHERE expenses.company_id = ? AND expenses.is_deleted = 0 AND expenses.date BETWEEN ? AND ? AND IFNULL(expenses.currency_id, ?) = ?
            GROUP BY k", [$cid, $s, $e, $this->currency, $this->currency]))->pluck('amount', 'k');

        // Build a continuous axis so empty periods show as zero.
        $labels = [];
        $cursor = $this->from->copy();
        $guard = 0;

        while ($cursor->lte($this->to) && $guard++ < 400) {
            $labels[] = match ($grain) {
                'day' => $cursor->format('Y-m-d'),
                'week' => $cursor->format('o-\WW'),
                'year' => $cursor->format('Y'),
                default => $cursor->format('Y-m'),
            };

            match ($grain) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'year' => $cursor->addYear(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return collect(array_unique($labels))->map(fn ($l) => [
            'label' => $l,
            'revenue' => round((float) ($rev[$l] ?? 0), 2),
            'expenses' => round((float) ($exp[$l] ?? 0), 2),
        ])->values()->all();
    }

    /* ------------------------------ Repairs ------------------------------ */

    public function repairs(): array
    {
        $cid = $this->company->id;

        $created = RepairJob::query()->forCompany($cid)->whereBetween('created_at', [$this->from, $this->to]);

        $byStatus = (clone $created)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $avgHours = RepairJob::query()->forCompany($cid)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$this->from, $this->to])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS h')
            ->value('h');

        $deviceTypes = DB::table('repair_jobs')
            ->join('devices', 'devices.id', '=', 'repair_jobs.device_id')
            ->where('repair_jobs.company_id', $cid)
            ->whereNull('repair_jobs.deleted_at')
            ->whereBetween('repair_jobs.created_at', [$this->from, $this->to])
            ->selectRaw('devices.device_type AS label, COUNT(*) AS c')
            ->groupBy('devices.device_type')
            ->orderByDesc('c')
            ->limit(6)
            ->get();

        $pending = ['pending', 'awaiting_approval', 'approved', 'assigned', 'in_progress', 'waiting_for_parts'];

        return [
            'total' => (int) $byStatus->sum(),
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'pending' => (int) collect($pending)->sum(fn ($st) => $byStatus[$st] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            'by_status' => $byStatus->all(),
            'avg_completion_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
            'device_types' => $deviceTypes->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->c])->all(),
            'open_now' => RepairJob::query()->forCompany($cid)->whereIn('status', RepairJob::OPEN_STATUSES)->count(),
            'ready_to_invoice' => RepairJob::query()->forCompany($cid)->where('status', 'completed')->whereNull('invoice_id')->whereNull('completion_verified_at')->count(),
        ];
    }

    /* ------------------------------ Inventory ------------------------------ */

    public function inventory(): array
    {
        $cid = $this->company->id;

        $items = DB::table('products')->where('company_id', $cid)->where('is_deleted', false)->whereNull('deleted_at')->where('track_stock', true);

        $topParts = InventoryMovement::query()
            ->forCompany($cid)
            ->where('type', 'repair_usage')
            ->whereBetween('inventory_movements.created_at', [$this->from, $this->to])
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->selectRaw('products.product_key AS label, SUM(-inventory_movements.quantity) AS qty')
            ->groupBy('products.product_key')
            ->orderByDesc('qty')
            ->limit(6)
            ->get();

        $movement = InventoryMovement::query()->forCompany($cid)->whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS qty_in, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS qty_out')
            ->first();

        return [
            'total_items' => (clone $items)->count(),
            'low_stock' => (clone $items)->where('in_stock_quantity', '>', 0)->whereColumn('in_stock_quantity', '<=', 'stock_notification_threshold')->count(),
            'out_of_stock' => (clone $items)->where('in_stock_quantity', '<=', 0)->count(),
            'stock_value' => round((float) (clone $items)->where('in_stock_quantity', '>', 0)->sum(DB::raw('in_stock_quantity * cost')), 2),
            'top_parts' => $topParts->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->qty])->all(),
            'qty_in' => (int) ($movement->qty_in ?? 0),
            'qty_out' => (int) ($movement->qty_out ?? 0),
        ];
    }

    /* ------------------------------ Customers ------------------------------ */

    public function customers(): array
    {
        $cid = $this->company->id;
        $clients = DB::table('clients')->where('company_id', $cid)->where('is_deleted', false)->whereNull('deleted_at');

        $repeat = DB::table('invoices')
            ->where('company_id', $cid)->where('is_deleted', false)->whereIn('status_id', [2, 3, 4])
            ->select('client_id')->groupBy('client_id')->havingRaw('COUNT(*) >= 2')->get()->count();

        $repeatRepairs = DB::table('repair_jobs')->where('company_id', $cid)->whereNull('deleted_at')
            ->select('client_id')->groupBy('client_id')->havingRaw('COUNT(*) >= 2')->pluck('client_id');

        $topBalances = (clone $clients)->where('balance', '>', 0)->orderByDesc('balance')->limit(8)
            ->get(['id', 'name', 'balance', 'paid_to_date']);

        return [
            'total' => (clone $clients)->count(),
            'new' => (clone $clients)->whereBetween('created_at', [$this->from, $this->to])->count(),
            'repeat' => $repeat,
            'repeat_repair_customers' => $repeatRepairs->count(),
            'top_balances' => $topBalances->map(fn ($c) => ['id' => $c->id, 'name' => $c->name ?: 'Client #' . $c->id, 'balance' => round((float) $c->balance, 2), 'paid_to_date' => round((float) $c->paid_to_date, 2)])->all(),
        ];
    }

    /* ------------------------------ Expenses ------------------------------ */

    /** Monthly expense totals per category (company currency). */
    public function expenseSummary(): array
    {
        [$s, $e] = $this->range();

        $rows = DB::select("
            SELECT DATE_FORMAT(expenses.date, '%Y-%m') AS month, COALESCE(expense_categories.name, 'Uncategorised') AS category,
              SUM(CASE WHEN expenses.uses_inclusive_taxes = 0 THEN expenses.amount
                + (COALESCE(expenses.tax_amount1,0) + COALESCE(expenses.tax_amount2,0) + COALESCE(expenses.tax_amount3,0))
                + ((expenses.amount * COALESCE(expenses.tax_rate1,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate2,0)/100) + (expenses.amount * COALESCE(expenses.tax_rate3,0)/100))
                ELSE expenses.amount END) AS amount,
              COUNT(*) AS c
            FROM expenses
            LEFT JOIN expense_categories ON expense_categories.id = expenses.category_id
            WHERE expenses.company_id = ? AND expenses.is_deleted = 0 AND expenses.date BETWEEN ? AND ? AND IFNULL(expenses.currency_id, ?) = ?
            GROUP BY month, category ORDER BY month DESC, amount DESC", [$this->company->id, $s, $e, $this->currency, $this->currency]);

        return collect($rows)->map(fn ($r) => ['month' => $r->month, 'category' => $r->category, 'amount' => round((float) $r->amount, 2), 'count' => (int) $r->c])->all();
    }

    public function currencyCode(): string
    {
        return \App\Models\Currency::find($this->currency)?->code ?? '';
    }
}
