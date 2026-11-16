@extends('portal.ninja2020.layout.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\Warranty')
@section('meta_title', 'Warranty')
@push('head')<link rel="stylesheet" href="{{ asset('css/pcfix-portal.css') }}?v=1">@endpush

@section('body')
<div class="pcx">
    @if (session('message'))<div class="alert">{{ session('message') }}</div>@endif
    @if ($errors->any())<div class="alert alert-error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <div class="head"><div><h1>Warranty</h1><p>Your repair warranties and claims.</p></div></div>

    <div class="card">
        <div class="card-head"><h2>My warranties</h2></div>
        <div class="wrap"><table>
            <thead><tr><th>Warranty</th><th>Device</th><th>Coverage</th><th>Valid until</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($warranties as $w)
                <tr><td>{{ $w->warranty_number }}</td><td>{{ $w->device?->label() ?? '—' }}<br><small class="muted">{{ $w->device?->serial_number }}</small></td>
                    <td>{{ Warranty::COVERAGE[$w->coverage_type] ?? '' }}</td><td>{{ $w->end_date->format('M j, Y') }}</td><td>{!! OpsUi::badge($w->status, $w->statusLabel()) !!}</td></tr>
            @empty
                <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">No warranties yet. A warranty is issued after each completed paid repair.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>

    <form method="POST" action="{{ route('client.ops.warranty.claims.store') }}" class="card">
        @csrf
        <div class="card-head"><h2>Submit a warranty claim</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="field"><label for="warranty">Warranty</label>
                    <select id="warranty" name="warranty"><option value="">I'm not sure — check by serial number</option>
                        @foreach ($warranties->whereIn('status', ['active', 'expiring_soon']) as $w)<option value="{{ $w->hashed_id }}">{{ $w->warranty_number }} — {{ $w->device?->label() }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="serial_number">Device serial number</label><input id="serial_number" type="text" name="serial_number" maxlength="150"></div>
            </div>
            <div class="field"><label for="issue_description">What is wrong? *</label><textarea id="issue_description" name="issue_description" required minlength="10" maxlength="3000"></textarea>
                <div class="hint">We check your coverage automatically; a technician then verifies the claim.</div></div>
            <button class="btn btn-primary">Submit claim</button>
        </div>
    </form>

    <div class="card">
        <div class="card-head"><h2>My claims</h2></div>
        <div class="wrap"><table>
            <thead><tr><th>Claim</th><th>Submitted</th><th>Coverage check</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($claims as $c)
                <tr><td>{{ $c->claim_number }}</td><td>{{ $c->created_at->format('M j, Y') }}</td><td><small>{{ $c->eligibility['reason'] ?? '' }}</small></td>
                    <td>{!! OpsUi::badge($c->status, $c->statusLabel()) !!} @if ($c->repairJob)<br><a href="{{ route('client.ops.repairs.show', $c->repairJob->hashed_id) }}">Track repair</a>@endif</td></tr>
            @empty
                <tr><td colspan="4" class="muted" style="text-align:center;padding:24px">No claims submitted.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
@endsection
