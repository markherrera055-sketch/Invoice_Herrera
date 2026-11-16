@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\Warranty')
@section('title', 'Warranty Records')

@section('content')
<div class="page-head">
    <div><h1>Warranty records</h1><p>Created automatically when a paid repair is verified and invoiced.</p></div>
    <form method="GET" action="{{ route('ops.warranty.check') }}" class="filters">
        <div class="field"><label for="serial">Check coverage by serial number</label><input id="serial" type="text" name="serial" required maxlength="150" placeholder="Serial number"></div>
        <button class="btn">Check</button>
    </form>
</div>
<div class="tabs">
    <a href="{{ route('ops.warranty.index') }}" class="{{ request('status') ? '' : 'active' }}">All <span class="count">{{ $counts->sum() }}</span></a>
    @foreach (Warranty::STATUSES as $k => $label)
        <a href="{{ route('ops.warranty.index', ['status' => $k]) }}" class="{{ request('status') === $k ? 'active' : '' }}">{{ $label }} <span class="count">{{ $counts[$k] ?? 0 }}</span></a>
    @endforeach
</div>
<div class="card">
    <div class="card-head"><form method="GET" class="filters">@if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif<div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Warranty no., customer, serial" aria-label="Search"></div><button class="btn">Search</button></form></div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Warranty</th><th>Customer</th><th>Device</th><th>Coverage</th><th>Valid</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($warranties as $w)
            <tr>
                <td><a class="row-link" href="{{ route('ops.warranty.show', $w->hashed_id) }}">{{ $w->warranty_number }}</a><br><small>{{ $w->repairJob?->job_number }}</small></td>
                <td>{{ $w->client?->present()->name() }}</td>
                <td>{{ $w->device?->label() ?? '—' }}<br><small class="mono">{{ $w->device?->serial_number }}</small></td>
                <td>{{ Warranty::COVERAGE[$w->coverage_type] ?? $w->coverage_type }}</td>
                <td style="white-space:nowrap">{{ $w->start_date->format('M j, Y') }} – {{ $w->end_date->format('M j, Y') }}</td>
                <td>{!! OpsUi::badge($w->status, $w->statusLabel()) !!}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No warranty records.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $warranties->links('ops::partials.pagination') }}
</div>
@endsection
