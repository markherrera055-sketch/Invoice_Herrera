@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Financial Reports')

@section('content')
@php $f = $financial; @endphp
<div class="page-head">
    <div><h1>Financial reports</h1><p>{{ $metrics->from()->format('M j, Y') }} – {{ $metrics->to()->format('M j, Y') }} · {{ $metrics->currencyCode() }} · computed from live records</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('ops.finance.export', request()->query() + ['grain' => $grain]) }}">Export CSV</a>
        <button type="button" class="btn" onclick="window.print()">Print / save PDF</button>
    </div>
</div>
<div class="card card-body mb">@include('ops::partials.period_filter', ['withGrain' => true])</div>

@if ($f['other_currency_rows'])
    <div class="alert alert-warn">Some payments or expenses are in other currencies and are excluded from these {{ $metrics->currencyCode() }} totals.</div>
@endif

<h3 class="mb">Financial overview</h3>
<div class="grid g-4 mb">
    <div class="card stat"><div class="label">Total revenue</div><div class="value">{{ OpsUi::money($f['revenue']) }}</div><div class="sub">Payments received, net of refunds</div></div>
    <div class="card stat"><div class="label">Total expenses</div><div class="value">{{ OpsUi::money($f['expenses']) }}</div></div>
    <div class="card stat"><div class="label">Net income</div><div class="value">{{ OpsUi::money($f['net_income']) }}</div></div>
    <div class="card stat"><div class="label">Invoiced</div><div class="value">{{ OpsUi::money($f['invoiced']) }}</div><div class="sub">Invoices dated in period</div></div>
    <div class="card stat"><div class="label">Outstanding receivables</div><div class="value">{{ OpsUi::money($f['outstanding']) }}</div><div class="sub">{{ $f['outstanding_count'] }} open invoices (all time)</div></div>
    <div class="card stat"><div class="label">Overdue</div><div class="value">{{ OpsUi::money($f['overdue']) }}</div><div class="sub">{{ $f['overdue_count'] }} invoices past due</div></div>
    <div class="card stat"><div class="label">Paid invoices</div><div class="value">{{ $f['paid_count'] }}</div><div class="sub">Dated in period</div></div>
    <div class="card stat"><div class="label">Unpaid / partial</div><div class="value">{{ $f['unpaid_count'] }} / {{ $f['partial_count'] }}</div><div class="sub">Dated in period</div></div>
</div>

<div class="card mb">
    <div class="card-head"><h2>Sales trend ({{ $grain }})</h2></div>
    <div class="card-body">@include('ops::partials.trend_chart', ['trend' => $trend])</div>
</div>

<div class="grid g-3 mb">
    <div class="card">
        <div class="card-head"><h2>Repair analytics</h2></div>
        <div class="card-body">
            <dl class="kv" style="grid-template-columns:1fr auto">
                <dt>Total jobs</dt><dd class="num">{{ $repairs['total'] }}</dd>
                <dt>Completed</dt><dd class="num">{{ $repairs['completed'] }}</dd>
                <dt>Pending</dt><dd class="num">{{ $repairs['pending'] }}</dd>
                <dt>Cancelled</dt><dd class="num">{{ $repairs['cancelled'] }}</dd>
                <dt>Avg. completion time</dt><dd class="num">{{ $repairs['avg_completion_hours'] !== null ? $repairs['avg_completion_hours'] . ' h' : '—' }}</dd>
            </dl>
            <h3 style="margin:14px 0 6px">Most repaired device types</h3>
            @include('ops::partials.hbars', ['items' => $repairs['device_types'], 'unit' => 'jobs'])
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Inventory analytics</h2></div>
        <div class="card-body">
            <dl class="kv" style="grid-template-columns:1fr auto">
                <dt>Tracked items</dt><dd class="num">{{ $inventory['total_items'] }}</dd>
                <dt>Low stock</dt><dd class="num">{{ $inventory['low_stock'] }}</dd>
                <dt>Out of stock</dt><dd class="num">{{ $inventory['out_of_stock'] }}</dd>
                <dt>Stock value (cost)</dt><dd class="num">{{ OpsUi::money($inventory['stock_value']) }}</dd>
                <dt>Units in / out</dt><dd class="num">{{ $inventory['qty_in'] }} / {{ $inventory['qty_out'] }}</dd>
            </dl>
            <h3 style="margin:14px 0 6px">Most used spare parts</h3>
            @include('ops::partials.hbars', ['items' => $inventory['top_parts'], 'unit' => 'units'])
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Customer analytics</h2></div>
        <div class="card-body">
            <dl class="kv" style="grid-template-columns:1fr auto">
                <dt>Total customers</dt><dd class="num">{{ $customers['total'] }}</dd>
                <dt>New in period</dt><dd class="num">{{ $customers['new'] }}</dd>
                <dt>Repeat (2+ invoices)</dt><dd class="num">{{ $customers['repeat'] }}</dd>
                <dt>Repeat repair customers</dt><dd class="num">{{ $customers['repeat_repair_customers'] }}</dd>
            </dl>
            <h3 style="margin:14px 0 6px">Highest outstanding balances</h3>
            @forelse ($customers['top_balances'] as $c)
                <div class="hbar" style="grid-template-columns:1fr auto"><a href="{{ route('ops.customers.show', (new \App\Models\Client())->encodePrimaryKey($c['id'])) }}">{{ $c['name'] }}</a><span class="num">{{ OpsUi::money($c['balance']) }}</span></div>
            @empty
                <div class="empty">No outstanding balances.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
