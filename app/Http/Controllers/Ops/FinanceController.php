<?php

namespace App\Http\Controllers\Ops;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Ops\DashboardMetrics;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 6: financial reporting & expense summaries (Invoice Ninja expenses).
 */
class FinanceController extends OpsController
{
    use Concerns\StreamsCsv;

    private function range(Request $request): array
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'period' => 'nullable|in:this_month,last_month,this_year,last_year,custom',
            'grain' => 'nullable|in:day,week,month,year',
        ]);

        return match ($request->input('period')) {
            'this_month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'last_year' => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
            'custom' => [$request->input('from'), $request->input('to')],
            default => [$request->input('from') ?: now()->startOfYear()->toDateString(), $request->input('to') ?: now()->toDateString()],
        };
    }

    public function expenses(Request $request)
    {
        [$from, $to] = $this->range($request);
        $request->validate(['category' => 'nullable|integer']);

        $metrics = DashboardMetrics::forRange($this->company(), $from, $to);

        $query = Expense::query()->where('company_id', $this->companyId())->where('is_deleted', false)
            ->whereBetween('date', [$metrics->from()->toDateString(), $metrics->to()->toDateString()])
            ->with(['vendor', 'category']);

        if ($c = $request->input('category')) {
            $query->where('category_id', $c);
        }

        return view('ops::finance.expenses', [
            'metrics' => $metrics,
            'expenses' => $query->orderByDesc('date')->paginate(30)->withQueryString(),
            'summary' => $metrics->expenseSummary(),
            'categories' => ExpenseCategory::query()->where('company_id', $this->companyId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reports(Request $request)
    {
        [$from, $to] = $this->range($request);
        $metrics = DashboardMetrics::forRange($this->company(), $from, $to);
        $grain = $request->input('grain', $metrics->from()->diffInDays($metrics->to()) <= 62 ? 'day' : 'month');

        return view('ops::finance.reports', [
            'metrics' => $metrics,
            'financial' => $metrics->financial(),
            'trend' => $metrics->salesTrend($grain),
            'grain' => $grain,
            'repairs' => $metrics->repairs(),
            'inventory' => $metrics->inventory(),
            'customers' => $metrics->customers(),
        ]);
    }

    /** CSV export of the same figures shown on the reports page. */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $m = DashboardMetrics::forRange($this->company(), $from, $to);
        $grain = $request->input('grain', 'month');

        $rows = [['PCFix Hub financial report', $m->from()->toDateString() . ' to ' . $m->to()->toDateString(), $m->currencyCode()], []];

        $rows[] = ['Financial overview'];
        foreach ($m->financial() as $k => $v) {
            $rows[] = [str_replace('_', ' ', $k), $v];
        }

        $rows[] = [];
        $rows[] = ['Sales trend (' . $grain . ')', 'Revenue', 'Expenses', 'Net'];
        foreach ($m->salesTrend($grain) as $r) {
            $rows[] = [$r['label'], $r['revenue'], $r['expenses'], round($r['revenue'] - $r['expenses'], 2)];
        }

        $rows[] = [];
        $rows[] = ['Repair analytics'];
        $repairs = $m->repairs();
        foreach (['total', 'completed', 'pending', 'cancelled', 'avg_completion_hours', 'open_now', 'ready_to_invoice'] as $k) {
            $rows[] = [str_replace('_', ' ', $k), $repairs[$k]];
        }
        foreach ($repairs['device_types'] as $d) {
            $rows[] = ['device type: ' . $d['label'], $d['value']];
        }

        $rows[] = [];
        $rows[] = ['Inventory analytics'];
        $inv = $m->inventory();
        foreach (['total_items', 'low_stock', 'out_of_stock', 'stock_value', 'qty_in', 'qty_out'] as $k) {
            $rows[] = [str_replace('_', ' ', $k), $inv[$k]];
        }
        foreach ($inv['top_parts'] as $p) {
            $rows[] = ['used part: ' . $p['label'], $p['value']];
        }

        $rows[] = [];
        $rows[] = ['Customer analytics'];
        $cust = $m->customers();
        foreach (['total', 'new', 'repeat', 'repeat_repair_customers'] as $k) {
            $rows[] = [str_replace('_', ' ', $k), $cust[$k]];
        }
        $rows[] = ['Top outstanding balances', 'Balance', 'Paid to date'];
        foreach ($cust['top_balances'] as $c) {
            $rows[] = [$c['name'], $c['balance'], $c['paid_to_date']];
        }

        $rows[] = [];
        $rows[] = ['Expense summary', 'Category', 'Amount', 'Count'];
        foreach ($m->expenseSummary() as $e) {
            $rows[] = [$e['month'], $e['category'], $e['amount'], $e['count']];
        }

        return $this->csv('pcfix-report-' . $m->from()->format('Ymd') . '-' . $m->to()->format('Ymd') . '.csv', $rows);
    }
}
