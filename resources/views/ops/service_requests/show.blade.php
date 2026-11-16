@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\ServiceRequest')
@section('title', $sr->request_number)

@php $manage = in_array('service_requests.manage', $opsAbilities); @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $sr->request_number }} {!! OpsUi::badge($sr->status, $sr->statusLabel()) !!}</h1>
        <p>{{ ServiceRequest::CHANNELS[$sr->channel] ?? $sr->channel }} · received {{ $sr->created_at->format('M j, Y g:i A') }}</p>
    </div>
    <div class="actions">
        @if ($sr->repairJob)<a class="btn btn-primary" href="{{ route('ops.repair_jobs.show', $sr->repairJob->hashed_id) }}">Open repair job {{ $sr->repairJob->job_number }}</a>@endif
    </div>
</div>

{{-- Workflow guide --}}
<ol class="steps card card-body mb" aria-label="Intake progress">
    @php
        $stage = match (true) {
            in_array($sr->status, ['converted', 'approved']) => 5,
            $sr->status === 'quoted' => 4,
            (bool) $sr->client_id => 3,
            default => 1,
        };
    @endphp
    @foreach (['Submitted', 'Validated', 'Customer checked', 'Quotation sent', 'Customer approved'] as $i => $step)
        <li class="{{ $i < max($stage, 2) ? 'done' : '' }}">{{ $step }}</li>
    @endforeach
</ol>

<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Request details</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Customer</dt><dd>{{ $sr->customer_name }}</dd>
                    <dt>Email</dt><dd>{{ $sr->email ?: '—' }}</dd>
                    <dt>Contact number</dt><dd>{{ $sr->phone ?: '—' }}</dd>
                    <dt>Address</dt><dd>{{ $sr->address ?: '—' }}</dd>
                    <dt>Device</dt><dd>{{ $sr->device_type }} · {{ $sr->device_brand }} {{ $sr->device_model }}</dd>
                    <dt>Serial number</dt><dd class="mono">{{ $sr->serial_number ?: '—' }}</dd>
                    <dt>Reported issue</dt><dd style="white-space:pre-line">{{ $sr->reported_issue }}</dd>
                    <dt>Preferred date</dt><dd>{{ $sr->preferred_service_date?->format('M j, Y') ?? '—' }}</dd>
                    <dt>Branch</dt><dd>{{ $sr->branch ?: '—' }}</dd>
                    <dt>Priority</dt><dd>{!! OpsUi::priority($sr->priority) !!}</dd>
                    <dt>Notes</dt><dd style="white-space:pre-line">{{ $sr->notes ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        @if ($manage && $sr->isOpen() && $sr->client_id && (! $sr->repairJob || $sr->repairJob->status === 'pending'))
        <div class="card">
            <div class="card-head"><h2>Evaluate &amp; send quotation</h2><small>Creates the repair job and an Invoice Ninja quote the customer approves online.</small></div>
            <form method="POST" action="{{ route('ops.service_requests.quote', $sr->hashed_id) }}" class="card-body">
                @csrf
                <div class="field"><label for="diagnosis">Initial diagnosis *</label><textarea id="diagnosis" name="diagnosis" required minlength="5" maxlength="3000">{{ old('diagnosis') }}</textarea></div>
                <div class="form-row">
                    <div class="field"><label for="q-priority">Priority</label>
                        <select id="q-priority" name="priority">@foreach (array_keys(config('pcfix.priorities')) as $p)<option value="{{ $p }}" @selected(old('priority', $sr->priority) === $p)>{{ ucfirst($p) }}</option>@endforeach</select></div>
                    <div class="field"><label for="q-skill">Required skill</label>
                        <select id="q-skill" name="required_skill"><option value="">Auto-detect</option>@foreach (config('pcfix.skills') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                </div>
                <label>Quotation lines</label>
                @include('ops::partials.line_editor', ['products' => $products, 'prefill' => true])
                <div class="mt"><button class="btn btn-primary">Create job &amp; send quotation</button></div>
            </form>
        </div>
        @elseif ($manage && $sr->isOpen() && ! $sr->client_id)
            <div class="alert alert-warn">Link this request to an existing customer or create a new customer record before evaluating it.</div>
        @endif

        @if ($manage && $sr->isOpen())
        <details class="card">
            <summary class="card-head" style="cursor:pointer"><h2>Edit request</h2></summary>
            <form method="POST" action="{{ route('ops.service_requests.update', $sr->hashed_id) }}" class="card-body">
                @csrf @method('PUT')
                @include('ops::partials.request_fields', ['sr' => $sr, 'withCustomer' => true, 'staff' => true])
                <button class="btn btn-primary">Save changes</button>
            </form>
        </details>
        @endif
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Customer record</h2></div>
            <div class="card-body">
                @if ($sr->client)
                    <p style="margin-top:0"><span class="badge b-good">Linked</span> <a class="row-link" href="{{ route('ops.customers.show', $sr->client->hashed_id) }}">{{ $sr->client->present()->name() }}</a></p>
                    <small>Client #{{ $sr->client->number }} · {{ $sr->client->contacts->first()?->email }}</small>
                    @if ($sr->device)<p class="mb" style="margin-bottom:0">Device registered: {{ $sr->device->label() }} <span class="mono">{{ $sr->device->serial_number }}</span></p>@endif
                @else
                    <p style="margin-top:0"><span class="badge b-serious">Not linked</span> No single exact match was found.</p>
                    @if ($candidates)
                        <h3 style="margin:12px 0 6px">Possible existing customers</h3>
                        @foreach ($candidates as $c)
                            <div style="border:1px solid var(--border);border-radius:6px;padding:10px;margin-bottom:8px">
                                <strong>{{ $c['name'] }}</strong> <span class="badge {{ $c['strength'] === 'strong' ? 'b-bad' : 'b-warn' }}">{{ $c['strength'] === 'strong' ? 'Strong match' : 'Possible match' }}</span><br>
                                <small>{{ $c['email'] }} {{ $c['phone'] ? '· ' . $c['phone'] : '' }}</small><br>
                                <small>{{ implode(', ', $c['reasons']) }}</small>
                                @if ($manage)
                                <form method="POST" action="{{ route('ops.service_requests.link_client', $sr->hashed_id) }}" style="margin-top:6px">
                                    @csrf <input type="hidden" name="client" value="{{ (new \App\Models\Client())->encodePrimaryKey($c['client_id']) }}">
                                    <button class="btn btn-sm">Use this customer</button>
                                </form>
                                @endif
                            </div>
                        @endforeach
                    @endif
                    @if ($manage)
                        <form method="POST" action="{{ route('ops.service_requests.create_client', $sr->hashed_id) }}" data-confirm="Create a NEW customer record? Only do this if none of the matches above is the same person.">
                            @csrf <button class="btn {{ $candidates ? '' : 'btn-primary' }}">Create new customer</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        @if ($sr->quote)
        <div class="card">
            <div class="card-head"><h2>Quotation</h2></div>
            <div class="card-body">
                <p style="margin:0">{{ $sr->quote->number ?: 'Draft' }} · {{ OpsUi::money($sr->quote->amount) }} {!! OpsUi::quoteStatus($sr->quote) !!}</p>
                <a href="{{ \App\Http\Controllers\Ops\OpsController::adminUrl('quotes/' . $sr->quote->hashed_id . '/edit') }}" target="_blank" rel="noopener">Open in Invoice Ninja ↗</a>
            </div>
        </div>
        @endif

        @if ($manage && $sr->isOpen())
        <form method="POST" action="{{ route('ops.service_requests.cancel', $sr->hashed_id) }}" class="card card-body" data-confirm="Cancel this service request?">
            @csrf
            <div class="field"><label for="reason">Cancel request</label><input id="reason" type="text" name="reason" required maxlength="500" placeholder="Reason"></div>
            <button class="btn btn-danger btn-sm">Cancel request</button>
        </form>
        @endif
    </div>
</div>
@endsection
