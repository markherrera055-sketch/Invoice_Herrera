@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\ServiceRequest')
@section('title', 'Service Requests')

@section('content')
<div class="page-head">
    <div><h1>Service requests</h1><p>Standardised intake from the online form, client portal, phone and walk-in.</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('ops.public.create', $opsCompany->company_key) }}" target="_blank" rel="noopener">Public form ↗</a>
        @if (in_array('service_requests.manage', $opsAbilities))<a class="btn btn-primary" href="{{ route('ops.service_requests.create') }}">+ New request</a>@endif
    </div>
</div>

<div class="tabs">
    <a href="{{ route('ops.service_requests.index') }}" class="{{ ! request('status') && ! request('duplicates') ? 'active' : '' }}">All <span class="count">{{ $counts->sum() }}</span></a>
    @foreach (ServiceRequest::STATUSES as $key => $label)
        <a href="{{ route('ops.service_requests.index', ['status' => $key]) }}" class="{{ request('status') === $key ? 'active' : '' }}">{{ $label }} <span class="count">{{ $counts[$key] ?? 0 }}</span></a>
    @endforeach
    <a href="{{ route('ops.service_requests.index', ['duplicates' => 1]) }}" class="{{ request('duplicates') ? 'active' : '' }}">Possible duplicates</a>
</div>

<div class="card">
    <div class="card-head">
        <form method="GET" class="filters">
            @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
            <div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Number, name, email, phone, serial" aria-label="Search"></div>
            <button class="btn">Search</button>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Request</th><th>Customer</th><th>Device</th><th>Channel</th><th>Status</th><th>Received</th></tr></thead>
        <tbody>
        @forelse ($requests as $sr)
            <tr>
                <td><a class="row-link" href="{{ route('ops.service_requests.show', $sr->hashed_id) }}">{{ $sr->request_number }}</a>
                    @if ($sr->possible_duplicate)<br><span class="badge b-warn">Check duplicate</span>@endif</td>
                <td>{{ $sr->customer_name }}<br><small>{{ $sr->email ?: $sr->phone }}</small>
                    @if (! $sr->client_id)<br><span class="badge b-serious">Not linked</span>@endif</td>
                <td>{{ $sr->device_type }} · {{ $sr->device_brand }} {{ $sr->device_model }}<br><small class="mono">{{ $sr->serial_number }}</small></td>
                <td>{{ ServiceRequest::CHANNELS[$sr->channel] ?? $sr->channel }}</td>
                <td>{!! OpsUi::badge($sr->status, $sr->statusLabel()) !!}
                    @if ($sr->repairJob)<br><small><a href="{{ route('ops.repair_jobs.show', $sr->repairJob->hashed_id) }}">{{ $sr->repairJob->job_number }}</a></small>@endif</td>
                <td style="white-space:nowrap">{{ $sr->created_at->format('M j, Y') }}<br><small>{{ $sr->created_at->diffForHumans() }}</small></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No service requests match.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $requests->links('ops::partials.pagination') }}
</div>
@endsection
