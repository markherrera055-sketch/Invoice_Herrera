@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', $client->present()->name())

@section('content')
@php $pc = $client->contacts->first(); @endphp
<div class="page-head">
    <div><h1>{{ $client->present()->name() }}</h1><p>#{{ $client->number }} · {{ $pc?->email }} · {{ $client->phone ?: $pc?->phone }}</p></div>
    <a class="btn" href="{{ OpsController::adminUrl('clients/' . $client->hashed_id) }}" target="_blank" rel="noopener">Open in Invoice Ninja ↗</a>
</div>
@if ($canBilling)
<div class="grid g-4 mb">
    <div class="card stat"><div class="label">Outstanding balance</div><div class="value">{{ OpsUi::money($client->balance) }}</div></div>
    <div class="card stat"><div class="label">Paid to date</div><div class="value">{{ OpsUi::money($client->paid_to_date) }}</div></div>
    <div class="card stat"><div class="label">Repair jobs</div><div class="value">{{ $jobs->count() }}</div></div>
    <div class="card stat"><div class="label">Active warranties</div><div class="value">{{ $warranties->whereIn('status', ['active', 'expiring_soon', 'claimed'])->count() }}</div></div>
</div>
@endif
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Repair history</h2></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Job</th><th>Device</th><th>Technician</th><th>Status</th><th>Invoice</th></tr></thead>
                <tbody>
                @forelse ($jobs as $j)
                    <tr><td><a class="row-link" href="{{ route('ops.repair_jobs.show', $j->hashed_id) }}">{{ $j->job_number }}</a><br><small>{{ $j->created_at->format('M j, Y') }}</small></td>
                        <td>{{ $j->device?->label() }}</td><td>{{ $j->technician?->present()->name() ?? '—' }}</td>
                        <td>{!! OpsUi::badge($j->status, $j->statusLabel()) !!}</td><td>{{ $j->invoice?->number ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No repair jobs yet.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        @if ($canBilling)
        <div class="card">
            <div class="card-head"><h2>Transaction history</h2></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Invoice</th><th>Date</th><th class="num">Amount</th><th class="num">Balance</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($invoices as $inv)
                    <tr><td>{{ $inv->number }}</td><td>{{ \Illuminate\Support\Carbon::parse($inv->date)->format('M j, Y') }}</td><td class="num">{{ OpsUi::money($inv->amount) }}</td><td class="num">{{ OpsUi::money($inv->balance) }}</td><td>{!! OpsUi::invoiceStatus($inv) !!}</td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No invoices.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        @endif
    </div>
    <div class="stack">
        <div class="card"><div class="card-head"><h2>Devices</h2></div><div class="card-body">
            @forelse ($devices as $d)
                <div style="padding:4px 0"><strong>{{ $d->device_type }}</strong> · {{ $d->label() }}<br><small class="mono">{{ $d->serial_number ?: 'no serial' }}</small> <small>· {{ $d->repair_jobs_count }} repairs</small></div>
            @empty <span class="muted">No devices registered.</span> @endforelse
        </div></div>
        <div class="card"><div class="card-head"><h2>Warranties</h2></div><div class="card-body">
            @forelse ($warranties as $w)
                <div style="padding:4px 0"><a href="{{ route('ops.warranty.show', $w->hashed_id) }}">{{ $w->warranty_number }}</a> {!! OpsUi::badge($w->status, $w->statusLabel()) !!}<br><small>{{ $w->device?->label() }} · until {{ $w->end_date->format('M j, Y') }}</small></div>
            @empty <span class="muted">No warranties.</span> @endforelse
        </div></div>
        <div class="card"><div class="card-head"><h2>Service requests</h2></div><div class="card-body">
            @forelse ($requests as $r)
                <div style="padding:4px 0"><a href="{{ route('ops.service_requests.show', $r->hashed_id) }}">{{ $r->request_number }}</a> {!! OpsUi::badge($r->status, $r->statusLabel()) !!}<br><small>{{ $r->created_at->format('M j, Y') }} · {{ $r->device_type }}</small></div>
            @empty <span class="muted">No requests.</span> @endforelse
        </div></div>
    </div>
</div>
@endsection
