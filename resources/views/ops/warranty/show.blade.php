@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\Warranty')
@section('title', $w->warranty_number)

@section('content')
<div class="page-head">
    <div><h1>{{ $w->warranty_number }} {!! OpsUi::badge($w->status, $w->statusLabel()) !!}</h1>
        <p>{{ $w->client?->present()->name() }} · {{ $w->device?->label() ?? 'No device' }} @if ($w->device?->serial_number)· S/N <span class="mono">{{ $w->device->serial_number }}</span>@endif</p></div>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Coverage</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Coverage</dt><dd>{{ Warranty::COVERAGE[$w->coverage_type] ?? $w->coverage_type }}</dd>
                    <dt>Valid</dt><dd>{{ $w->start_date->format('M j, Y') }} – {{ $w->end_date->format('M j, Y') }} ({{ $w->end_date->isPast() ? 'expired ' . $w->end_date->diffForHumans() : $w->end_date->diffForHumans(null, true) . ' left' }})</dd>
                    <dt>Covered items</dt><dd style="white-space:pre-line">{{ $w->covered_items ?: '—' }}</dd>
                    <dt>Exclusions</dt><dd style="white-space:pre-line">{{ $w->exclusions ?: '—' }}</dd>
                    <dt>Repair job</dt><dd>@if ($w->repairJob)<a href="{{ route('ops.repair_jobs.show', $w->repairJob->hashed_id) }}">{{ $w->repairJob->job_number }}</a>@else — @endif</dd>
                    <dt>Invoice</dt><dd>{{ $w->invoice?->number ?? '—' }}</dd>
                    <dt>Expiry notice</dt><dd>{{ $w->expiry_notified_at ? 'Sent ' . $w->expiry_notified_at->format('M j, Y') : 'Not yet sent' }}</dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Claims &amp; warranty service history</h2></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Claim</th><th>Issue</th><th>Status</th><th>Repair job</th></tr></thead>
                <tbody>
                @forelse ($w->claims as $c)
                    <tr><td><a class="row-link" href="{{ route('ops.warranty.claims.show', $c->hashed_id) }}">{{ $c->claim_number }}</a><br><small>{{ $c->created_at->format('M j, Y') }}</small></td>
                        <td>{{ \Illuminate\Support\Str::limit($c->issue_description, 90) }}</td>
                        <td>{!! OpsUi::badge($c->status, $c->statusLabel()) !!}</td>
                        <td>@if ($c->repairJob)<a href="{{ route('ops.repair_jobs.show', $c->repairJob->hashed_id) }}">{{ $c->repairJob->job_number }}</a>@else — @endif</td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No claims against this warranty.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    </div>
    @if (in_array('warranty.manage', $opsAbilities))
    <form method="POST" action="{{ route('ops.warranty.update', $w->hashed_id) }}" class="card">
        @csrf @method('PUT')
        <div class="card-head"><h2>Edit warranty terms</h2></div>
        <div class="card-body">
            <div class="field"><label for="coverage_type">Coverage</label><select id="coverage_type" name="coverage_type">@foreach (Warranty::COVERAGE as $k => $l)<option value="{{ $k }}" @selected($w->coverage_type === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label for="end_date">Expiration date</label><input id="end_date" type="date" name="end_date" value="{{ $w->end_date->toDateString() }}" required></div>
            <div class="field"><label for="covered_items">Covered parts &amp; services</label><textarea id="covered_items" name="covered_items" maxlength="3000">{{ $w->covered_items }}</textarea></div>
            <div class="field"><label for="exclusions">Exclusions</label><textarea id="exclusions" name="exclusions" maxlength="3000">{{ $w->exclusions }}</textarea></div>
            <div class="field"><label class="check"><input type="checkbox" name="void" value="1" @checked($w->status === 'void')> Void this warranty</label></div>
            <button class="btn btn-primary">Save</button>
        </div>
    </form>
    @endif
</div>
@endsection
