@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\Warranty')
@section('title', $claim->claim_number)

@section('content')
@php $e = $claim->eligibility ?? []; @endphp
<div class="page-head">
    <div><h1>{{ $claim->claim_number }} {!! OpsUi::badge($claim->status, $claim->statusLabel()) !!}</h1>
        <p>{{ $claim->client?->present()->name() }} · submitted {{ $claim->created_at->format('M j, Y g:i A') }} via {{ $claim->submitted_via }}</p></div>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Claim</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Issue</dt><dd style="white-space:pre-line">{{ $claim->issue_description }}</dd>
                    <dt>Serial number</dt><dd class="mono">{{ $claim->serial_number ?: '—' }}</dd>
                    <dt>Contact</dt><dd>{{ $claim->contact?->email ?? '—' }}</dd>
                    <dt>Warranty</dt><dd>@if ($claim->warranty)<a href="{{ route('ops.warranty.show', $claim->warranty->hashed_id) }}">{{ $claim->warranty->warranty_number }}</a> · {{ Warranty::COVERAGE[$claim->warranty->coverage_type] ?? '' }} · until {{ $claim->warranty->end_date->format('M j, Y') }}@else None found @endif</dd>
                    <dt>Original repair</dt><dd>@if ($claim->warranty?->repairJob)<a href="{{ route('ops.repair_jobs.show', $claim->warranty->repairJob->hashed_id) }}">{{ $claim->warranty->repairJob->job_number }}</a>@else — @endif</dd>
                    <dt>Repair job</dt><dd>@if ($claim->repairJob)<a href="{{ route('ops.repair_jobs.show', $claim->repairJob->hashed_id) }}">{{ $claim->repairJob->job_number }}</a> {!! OpsUi::badge($claim->repairJob->status, $claim->repairJob->statusLabel()) !!}@else — @endif</dd>
                    @if ($claim->verified_at)
                        <dt>Verified by</dt><dd>{{ $claim->verifier?->present()->name() }} · {{ $claim->verified_at->format('M j, Y g:i A') }}</dd>
                        <dt>Verification notes</dt><dd style="white-space:pre-line">{{ $claim->verification_notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Automatic eligibility check</h2></div>
            <div class="card-body">
                <p style="margin-top:0">{!! ($e['eligible'] ?? false) ? '<span class="badge b-good">Eligible</span>' : '<span class="badge b-bad">Not eligible</span>' !!}</p>
                <p>{{ $e['reason'] ?? '' }}</p>
                <small>Checked {{ $e['checked_at'] ?? '' }}. Re-checked when the technician decides.</small>
            </div>
        </div>
        @if ($claim->status === 'submitted' && in_array('warranty.manage', $opsAbilities))
        <form method="POST" action="{{ route('ops.warranty.claims.decide', $claim->hashed_id) }}" class="card">
            @csrf
            <div class="card-head"><h2>Technician verification</h2></div>
            <div class="card-body">
                <div class="field"><label for="notes">Findings *</label><textarea id="notes" name="notes" required minlength="3" maxlength="2000" placeholder="Inspection result, cause of failure, exclusion applied…"></textarea></div>
                <div class="field"><label class="check"><input type="checkbox" name="create_paid_job" value="1" checked> If rejected, open a paid repair job for a quotation</label></div>
                <div class="actions">
                    <button class="btn btn-primary" name="decision" value="approve" @disabled(! ($e['eligible'] ?? false))>Approve — free repair</button>
                    <button class="btn btn-danger" name="decision" value="reject">Reject — not covered</button>
                </div>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection
