@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Dashboard')

@section('content')
<div class="page-head">
    <div>
        <h1>Operations dashboard</h1>
        <p>{{ $metrics->from()->format('M j, Y') }} – {{ $metrics->to()->format('M j, Y') }} · live data</p>
    </div>
    <form method="GET" class="filters no-print">
        <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $metrics->from()->toDateString() }}"></div>
        <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $metrics->to()->toDateString() }}"></div>
        <button class="btn">Apply</button>
    </form>
</div>

{{-- Work queues: what needs attention right now --}}
@php $q = $queues; @endphp
<div class="grid g-4 mb">
    @if (! is_null($q['new_requests']))
        <a class="card stat" href="{{ route('ops.service_requests.index', ['status' => 'new']) }}"><div class="label">Requests to review</div><div class="value">{{ $q['new_requests'] }}</div><div class="sub">New / under review</div></a>
    @endif
    @if (! is_null($q['unassigned']))
        <a class="card stat" href="{{ route('ops.repair_jobs.index', ['status' => 'approved']) }}"><div class="label">Unassigned jobs</div><div class="value">{{ $q['unassigned'] }}</div><div class="sub">Approved, waiting for a technician</div></a>
    @endif
    @if (! is_null($q['awaiting_approval']))
        <a class="card stat" href="{{ route('ops.repair_jobs.index', ['status' => 'awaiting_approval']) }}"><div class="label">Awaiting customer</div><div class="value">{{ $q['awaiting_approval'] }}</div><div class="sub">Quotations pending approval</div></a>
    @endif
    @if (in_array('billing.view', $opsAbilities))
        <a class="card stat" href="{{ route('ops.billing.index') }}"><div class="label">Ready to invoice</div><div class="value">{{ $repairs['ready_to_invoice'] }}</div><div class="sub">Completed, not yet verified</div></a>
    @endif
    @if (! is_null($q['claims']))
        <a class="card stat" href="{{ route('ops.warranty.claims.index', ['status' => 'submitted']) }}"><div class="label">Warranty claims</div><div class="value">{{ $q['claims'] }}</div><div class="sub">Waiting for verification</div></a>
    @endif
    @if (! is_null($q['purchase_pending']))
        <a class="card stat" href="{{ route('ops.purchase_requests.index', ['status' => 'pending']) }}"><div class="label">Purchase approvals</div><div class="value">{{ $q['purchase_pending'] }}</div><div class="sub">Requests pending decision</div></a>
    @endif
    @if ($inventory)
        <a class="card stat" href="{{ route('ops.inventory.low') }}"><div class="label">Low / out of stock</div><div class="value">{{ $inventory['low_stock'] + $inventory['out_of_stock'] }}</div><div class="sub">{{ $inventory['out_of_stock'] }} out of stock</div></a>
    @endif
</div>

@if ($myJobs->count())
<div class="card mb">
    <div class="card-head"><h2>My active repair jobs</h2><a href="{{ route('ops.repair_jobs.index') }}">View all</a></div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Job</th><th>Customer</th><th>Device</th><th>Priority</th><th>Status</th></tr></thead>
        <tbody>
        @foreach ($myJobs as $job)
            <tr>
                <td><a class="row-link" href="{{ route('ops.repair_jobs.show', $job->hashed_id) }}">{{ $job->job_number }}</a></td>
                <td>{{ $job->client?->present()->name() }}</td>
                <td>{{ $job->device?->label() }}</td>
                <td>{!! OpsUi::priority($job->priority) !!}</td>
                <td>{!! OpsUi::badge($job->status, $job->statusLabel()) !!}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endif

@if ($financial)
<h3 class="mb">Financial overview</h3>
<div class="grid g-4 mb">
    <div class="card stat"><div class="label">Revenue</div><div class="value">{{ OpsUi::money($financial['revenue']) }}</div><div class="sub">Payments received in period</div></div>
    <div class="card stat"><div class="label">Expenses</div><div class="value">{{ OpsUi::money($financial['expenses']) }}</div><div class="sub">Incl. expense taxes</div></div>
    <div class="card stat"><div class="label">Net income</div><div class="value">{{ OpsUi::money($financial['net_income']) }}</div><div class="sub">Revenue − expenses</div></div>
    <div class="card stat"><div class="label">Outstanding receivables</div><div class="value">{{ OpsUi::money($financial['outstanding']) }}</div><div class="sub">{{ $financial['outstanding_count'] }} open invoices · {{ $financial['overdue_count'] }} overdue</div></div>
</div>
<div class="card mb">
    <div class="card-head"><h2>Revenue vs expenses</h2><a href="{{ route('ops.finance.reports') }}">Detailed reports</a></div>
    <div class="card-body">@include('ops::partials.trend_chart', ['trend' => $trend])</div>
</div>
@endif

<div class="grid g-2">
    <div class="card">
        <div class="card-head"><h2>Repair jobs in period</h2></div>
        <div class="card-body">
            <div class="grid g-4" style="gap:8px;margin-bottom:12px">
                <div><div class="muted">Total</div><strong style="font-size:18px">{{ $repairs['total'] }}</strong></div>
                <div><div class="muted">Completed</div><strong style="font-size:18px">{{ $repairs['completed'] }}</strong></div>
                <div><div class="muted">Pending</div><strong style="font-size:18px">{{ $repairs['pending'] }}</strong></div>
                <div><div class="muted">Avg. time</div><strong style="font-size:18px">{{ $repairs['avg_completion_hours'] !== null ? $repairs['avg_completion_hours'] . ' h' : '—' }}</strong></div>
            </div>
            <h3 style="margin-bottom:6px">Most repaired device types</h3>
            @include('ops::partials.hbars', ['items' => $repairs['device_types'], 'unit' => 'jobs', 'empty' => 'No repair jobs in this period.'])
        </div>
    </div>
    @if ($inventory)
    <div class="card">
        <div class="card-head"><h2>Most used spare parts</h2><a href="{{ route('ops.inventory.movements') }}">Movements</a></div>
        <div class="card-body">
            @include('ops::partials.hbars', ['items' => $inventory['top_parts'], 'unit' => 'units used', 'empty' => 'No parts used in repairs in this period.'])
            <p class="muted" style="margin-bottom:0">Stock in: {{ number_format($inventory['qty_in']) }} · out: {{ number_format($inventory['qty_out']) }} units · {{ $inventory['total_items'] }} tracked items</p>
        </div>
    </div>
    @endif
</div>
@endsection
