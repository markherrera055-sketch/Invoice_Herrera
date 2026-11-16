@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\WarrantyClaim')
@section('title', 'Warranty Claims')

@section('content')
<div class="page-head"><div><h1>Warranty claims</h1><p>Customer submits → system checks coverage → technician verifies → approved or rejected → repair processed → history updated.</p></div></div>
<div class="tabs">
    <a href="{{ route('ops.warranty.claims.index') }}" class="{{ request('status') ? '' : 'active' }}">All</a>
    @foreach (WarrantyClaim::STATUSES as $k => $l)<a href="{{ route('ops.warranty.claims.index', ['status' => $k]) }}" class="{{ request('status') === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach
</div>
<div class="grid g-main">
    <div class="card">
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Claim</th><th>Customer</th><th>Automatic check</th><th>Status</th><th>Job</th></tr></thead>
            <tbody>
            @forelse ($claims as $c)
                <tr>
                    <td><a class="row-link" href="{{ route('ops.warranty.claims.show', $c->hashed_id) }}">{{ $c->claim_number }}</a><br><small>{{ $c->created_at->format('M j, Y') }} · {{ $c->submitted_via }}</small></td>
                    <td>{{ $c->client?->present()->name() }}</td>
                    <td>{!! ($c->eligibility['eligible'] ?? false) ? '<span class="badge b-good">Eligible</span>' : '<span class="badge b-bad">Not eligible</span>' !!}<br><small>{{ $c->eligibility['reason'] ?? '' }}</small></td>
                    <td>{!! OpsUi::badge($c->status, $c->statusLabel()) !!}</td>
                    <td>@if ($c->repairJob)<a href="{{ route('ops.repair_jobs.show', $c->repairJob->hashed_id) }}">{{ $c->repairJob->job_number }}</a>@else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No warranty claims.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $claims->links('ops::partials.pagination') }}
    </div>
    @if (in_array('warranty.manage', $opsAbilities))
    <form method="POST" action="{{ route('ops.warranty.claims.store') }}" class="card">
        @csrf
        <div class="card-head"><h2>Record a claim</h2><small>Phone / walk-in</small></div>
        <div class="card-body">
            <div class="field"><label for="client">Customer</label><select id="client" name="client" required><option value="">Select…</option>@foreach ($clients as $cl)<option value="{{ $cl->hashed_id }}">{{ $cl->present()->name() }}</option>@endforeach</select></div>
            <div class="field"><label for="serial_number">Serial number</label><input id="serial_number" type="text" name="serial_number" maxlength="150"></div>
            <div class="field"><label for="issue_description">Issue *</label><textarea id="issue_description" name="issue_description" required minlength="5" maxlength="3000"></textarea></div>
            <button class="btn btn-primary">Submit claim</button>
        </div>
    </form>
    @endif
</div>
@endsection
