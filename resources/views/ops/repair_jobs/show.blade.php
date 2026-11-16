@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', $job->job_number)

@php
    $closed = in_array($job->status, ['completed', 'cancelled']);
    $working = in_array($job->status, ['assigned', 'in_progress', 'waiting_for_parts']);
    $pendingRevision = $job->hasPendingRevision();
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $job->job_number }} {!! OpsUi::badge($job->status, $job->statusLabel()) !!} {!! OpsUi::priority($job->priority) !!}
            @if ($job->is_warranty_job)<span class="badge b-info">Warranty repair</span>@endif</h1>
        <p>{{ $job->client?->present()->name() }} · {{ $job->device?->label() ?? 'No device recorded' }}
            @if ($job->device?->serial_number) · S/N <span class="mono">{{ $job->device->serial_number }}</span>@endif</p>
    </div>
    <div class="actions">
        @if ($canWork && $job->status === 'assigned')
            <form method="POST" action="{{ route('ops.repair_jobs.status', $job->hashed_id) }}">@csrf<input type="hidden" name="status" value="in_progress"><button class="btn btn-primary">Start work</button></form>
        @endif
        @if ($canWork && $job->status === 'in_progress')
            <form method="POST" action="{{ route('ops.repair_jobs.status', $job->hashed_id) }}">@csrf<input type="hidden" name="status" value="waiting_for_parts"><button class="btn">Waiting for parts</button></form>
        @endif
        @if ($canWork && $job->status === 'waiting_for_parts')
            <form method="POST" action="{{ route('ops.repair_jobs.status', $job->hashed_id) }}">@csrf<input type="hidden" name="status" value="in_progress"><button class="btn btn-primary">Resume work</button></form>
        @endif
    </div>
</div>

<ol class="steps card card-body mb" aria-label="Job progress">
    @php
        $flow = ['pending' => 0, 'awaiting_approval' => 1, 'approved' => 2, 'assigned' => 3, 'in_progress' => 4, 'waiting_for_parts' => 4, 'completed' => 5, 'cancelled' => -1];
        $at = $flow[$job->status] ?? 0;
    @endphp
    @foreach (['Created', 'Quoted', 'Approved', 'Assigned', 'In progress', 'Completed', 'Invoiced'] as $i => $label)
        <li class="{{ ($i <= $at || ($i === 6 && $job->invoice_id)) && $at >= 0 ? 'done' : '' }}">{{ $label }}</li>
    @endforeach
</ol>

@if ($pendingRevision)
    <div class="alert alert-warn">A revised quotation is waiting for the customer's approval. Additional work must not start until it is approved.</div>
@endif
@if ($job->status === 'approved' && ! $job->assigned_user_id)
    <div class="alert alert-warn">This job is approved but has no technician. {{ $isManager ? 'Use automatic assignment or pick a technician below.' : 'A service manager will assign it.' }}</div>
@endif

<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Job details</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Customer</dt><dd>@if ($job->client)<a href="{{ route('ops.customers.show', $job->client->hashed_id) }}">{{ $job->client->present()->name() }}</a>@endif</dd>
                    <dt>Device</dt><dd>{{ $job->device ? $job->device->device_type . ' · ' . $job->device->label() : '—' }}</dd>
                    <dt>Reported issue</dt><dd style="white-space:pre-line">{{ $job->reported_issue }}</dd>
                    <dt>Diagnosis</dt><dd style="white-space:pre-line">{{ $job->diagnosis ?: '—' }}</dd>
                    <dt>Required skill</dt><dd>{{ config('pcfix.skills')[$job->required_skill] ?? 'Any' }}</dd>
                    <dt>Technician</dt><dd>{{ $job->technician?->present()->name() ?? 'Unassigned' }} @if ($job->assignment_mode)<small>({{ $job->assignment_mode }})</small>@endif</dd>
                    <dt>Source</dt><dd>
                        @if ($job->serviceRequest)<a href="{{ route('ops.service_requests.show', $job->serviceRequest->hashed_id) }}">{{ $job->serviceRequest->request_number }}</a>@endif
                        @if ($job->warrantyClaim)<a href="{{ route('ops.warranty.claims.show', $job->warrantyClaim->hashed_id) }}">Claim {{ $job->warrantyClaim->claim_number }}</a>@endif
                    </dd>
                    <dt>Dates</dt><dd><small>Created {{ $job->created_at->format('M j, Y g:i A') }}@if ($job->started_at) · started {{ $job->started_at->format('M j g:i A') }}@endif @if ($job->completed_at) · completed {{ $job->completed_at->format('M j g:i A') }}@endif</small></dd>
                </dl>
            </div>
        </div>

        {{-- Quotations --}}
        <div class="card">
            <div class="card-head"><h2>Quotations</h2><small>Approved digitally by the customer in the client portal</small></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Quote</th><th>Type</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($quotes as $link)
                    <tr>
                        <td>{{ $link->quote?->number ?: 'Draft' }}</td>
                        <td>{{ ucfirst($link->type) }}</td>
                        <td class="num">{{ OpsUi::money($link->quote?->amount) }}</td>
                        <td>{!! OpsUi::quoteStatus($link->quote) !!}</td>
                        <td>@if ($link->quote)<a href="{{ OpsController::adminUrl('quotes/' . $link->quote->hashed_id . '/edit') }}" target="_blank" rel="noopener">Open ↗</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No quotation issued yet.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if ($job->status === 'pending' && in_array('service_requests.manage', $opsAbilities))
                <form method="POST" action="{{ route('ops.repair_jobs.quote', $job->hashed_id) }}" class="card-body" style="border-top:1px solid var(--border)">
                    @csrf
                    <label>Issue initial quotation</label>
                    @include('ops::partials.line_editor', ['products' => $products, 'prefill' => true])
                    <div class="mt"><button class="btn btn-primary">Send quotation</button></div>
                </form>
            @endif
        </div>

        {{-- Parts --}}
        <div class="card">
            <div class="card-head"><h2>Parts used</h2><small>Deducted from inventory immediately</small></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Part</th><th class="num">Qty</th><th class="num">Unit price</th><th>Recorded</th><th></th></tr></thead>
                <tbody>
                @forelse ($job->parts as $part)
                    <tr>
                        <td>{{ $part->product?->product_key }}<br><small>{{ $part->product?->notes }}</small>@if ($part->is_warranty_covered)<br><span class="badge b-info">Warranty covered</span>@endif</td>
                        <td class="num">{{ $part->quantity }}</td>
                        <td class="num">{{ OpsUi::money($part->unit_price) }}</td>
                        <td><small>{{ $part->user?->present()->name() }}<br>{{ $part->created_at->format('M j g:i A') }}</small></td>
                        <td>@if ($canWork && ! $job->invoice_id)
                            <form method="POST" action="{{ route('ops.repair_jobs.parts.remove', [$job->hashed_id, $part->hashed_id]) }}" data-confirm="Return this part to stock?">@csrf @method('DELETE')<button class="btn btn-sm">Return</button></form>
                        @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No parts recorded.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if ($unquoted)
                <div class="card-body" style="border-top:1px solid var(--border)"><span class="badge b-warn">Not on an approved quote</span>
                    @foreach ($unquoted as $u) <small>{{ $u['quantity'] }} × {{ $u['product_key'] }}</small>@endforeach
                    <small class="muted">— not billed unless accounting includes them.</small></div>
            @endif
            @if ($canWork && $working)
                <form method="POST" action="{{ route('ops.repair_jobs.parts.add', $job->hashed_id) }}" class="card-body filters" style="border-top:1px solid var(--border)">
                    @csrf
                    <div class="field" style="flex:1;min-width:220px"><label for="part-product">Record part used</label>
                        <select id="part-product" name="product" required>
                            <option value="">Select item…</option>
                            @foreach ($products->where('track_stock', true) as $p)<option value="{{ $p->hashed_id }}" @disabled($p->in_stock_quantity <= 0)>{{ $p->product_key }} — {{ \Illuminate\Support\Str::limit($p->notes, 40) }} (stock {{ $p->in_stock_quantity }})</option>@endforeach
                        </select></div>
                    <div class="field" style="min-width:90px;max-width:110px"><label for="part-qty">Qty</label><input id="part-qty" type="number" name="quantity" value="1" min="1" required></div>
                    <button class="btn">Record &amp; deduct</button>
                    @if (in_array('purchase_requests.create', $opsAbilities))<a class="btn btn-sm" href="{{ route('ops.purchase_requests.create') }}">Part not in stock? Request purchase</a>@endif
                </form>
            @endif
        </div>

        {{-- Technician workspace --}}
        @if ($canWork && ! $closed)
        <div class="card">
            <div class="card-head"><h2>Work notes &amp; diagnostics</h2></div>
            <form method="POST" action="{{ route('ops.repair_jobs.note', $job->hashed_id) }}" class="card-body">
                @csrf
                <div class="form-row">
                    <div class="field"><label for="note-type">Entry type</label><select id="note-type" name="type"><option value="note">Work note</option><option value="diagnosis">Diagnosis finding</option></select></div>
                    <div class="field" style="align-self:end"><label class="check"><input type="checkbox" name="customer_visible" value="1"> Visible to customer</label></div>
                </div>
                <div class="field"><textarea name="note" required maxlength="3000" aria-label="Note"></textarea></div>
                <button class="btn">Add entry</button>
            </form>
        </div>

        @if (in_array($job->status, ['in_progress', 'waiting_for_parts']) && ! $pendingRevision)
        <details class="card">
            <summary class="card-head" style="cursor:pointer"><h2>Request additional work (revised quotation)</h2></summary>
            <form method="POST" action="{{ route('ops.repair_jobs.revision', $job->hashed_id) }}" class="card-body">
                @csrf
                <div class="field"><label for="rev-reason">Why is extra work needed? *</label><input id="rev-reason" type="text" name="reason" required minlength="5" maxlength="1000"></div>
                @include('ops::partials.line_editor', ['products' => $products, 'prefill' => false])
                <div class="mt"><button class="btn btn-primary">Send revised quotation to customer</button></div>
            </form>
        </details>
        @endif

        @if ($job->status === 'in_progress' && ! $pendingRevision)
        <div class="card">
            <div class="card-head"><h2>Confirm service completion</h2></div>
            <form method="POST" action="{{ route('ops.repair_jobs.complete', $job->hashed_id) }}" class="card-body" data-confirm="Mark this repair as completed? Accounting will be notified to invoice it.">
                @csrf
                <div class="field"><label for="summary">Completion summary *</label><textarea id="summary" name="summary" required minlength="5" maxlength="3000" placeholder="Work performed, tests passed, recommendations"></textarea></div>
                <button class="btn btn-primary">Mark completed</button>
            </form>
        </div>
        @endif
        @endif
    </div>

    <div class="stack">
        @if ($isManager && ! $closed)
        <div class="card">
            <div class="card-head"><h2>Technician assignment</h2></div>
            <div class="card-body">
                @if (in_array($job->status, ['approved', 'assigned']))
                <form method="POST" action="{{ route('ops.repair_jobs.auto_assign', $job->hashed_id) }}" class="mb">@csrf<button class="btn btn-primary" style="width:100%">Auto-assign best technician</button></form>
                @endif
                <table class="t" style="font-size:13px">
                    <thead><tr><th>Technician</th><th class="num">Load</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse ($ranking as $row)
                        <tr>
                            <td>{{ $row['user']?->present()->name() }}<br><small>{{ $row['profile']->branch }}</small></td>
                            <td class="num" data-tip="Weighted workload (urgent 4, high 3, normal 2, low 1)">{{ $row['active'] }} jobs<br><small>weight {{ $row['load'] }}</small></td>
                            <td>{!! $row['eligible'] ? '<span class="badge b-good">Eligible</span>' : '<span class="badge">' . e($row['reason']) . '</span>' !!}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty">No technician profiles. Set them up under Technician Assignments.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @if (in_array($job->status, ['approved', 'assigned', 'in_progress', 'waiting_for_parts']) && $ranking->count())
                <form method="POST" action="{{ route('ops.repair_jobs.assign', $job->hashed_id) }}" class="mt">
                    @csrf
                    <div class="field"><label for="tech">Manual override</label>
                        <select id="tech" name="technician" required>@foreach ($ranking as $row)<option value="{{ $row['profile']->user_id }}" @selected($job->assigned_user_id === $row['profile']->user_id)>{{ $row['user']?->present()->name() }}{{ $row['eligible'] ? '' : ' (' . $row['reason'] . ')' }}</option>@endforeach</select></div>
                    <div class="field"><input type="text" name="reason" maxlength="500" placeholder="Reason for override (logged)" aria-label="Reason"></div>
                    <button class="btn">Assign</button>
                </form>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('ops.repair_jobs.priority', $job->hashed_id) }}" class="card card-body">
            @csrf
            <div class="form-row">
                <div class="field"><label for="prio">Priority</label><select id="prio" name="priority">@foreach (array_keys(config('pcfix.priorities')) as $p)<option value="{{ $p }}" @selected($job->priority === $p)>{{ ucfirst($p) }}</option>@endforeach</select></div>
                <div class="field"><label for="skill">Skill</label><select id="skill" name="required_skill"><option value="">Any</option>@foreach (config('pcfix.skills') as $k => $l)<option value="{{ $k }}" @selected($job->required_skill === $k)>{{ $l }}</option>@endforeach</select></div>
            </div>
            <button class="btn btn-sm">Update</button>
        </form>

        <form method="POST" action="{{ route('ops.repair_jobs.status', $job->hashed_id) }}" class="card card-body" data-confirm="Cancel this repair job?">
            @csrf <input type="hidden" name="status" value="cancelled">
            <div class="field"><label for="cancel-note">Cancel job</label><input id="cancel-note" type="text" name="note" maxlength="1000" placeholder="Reason" required></div>
            <button class="btn btn-danger btn-sm">Cancel job</button>
        </form>
        @endif

        @if ($job->invoice || $job->warranty)
        <div class="card">
            <div class="card-head"><h2>Billing &amp; warranty</h2></div>
            <div class="card-body">
                @if ($job->invoice)
                    <p style="margin-top:0">Invoice <strong>{{ $job->invoice->number }}</strong> · {{ OpsUi::money($job->invoice->amount) }} {!! OpsUi::invoiceStatus($job->invoice) !!}<br>
                    <small>Balance {{ OpsUi::money($job->invoice->balance) }}</small> · <a href="{{ OpsController::adminUrl('invoices/' . $job->invoice->hashed_id . '/edit') }}" target="_blank" rel="noopener">Open ↗</a></p>
                @endif
                @if ($job->warranty)
                    <p style="margin-bottom:0">Warranty <a href="{{ route('ops.warranty.show', $job->warranty->hashed_id) }}">{{ $job->warranty->warranty_number }}</a> until {{ $job->warranty->end_date->format('M j, Y') }} {!! OpsUi::badge($job->warranty->status, $job->warranty->statusLabel()) !!}</p>
                @endif
            </div>
        </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>Timeline</h2></div>
            <div class="card-body">
                <ul class="timeline">
                    @foreach ($job->updates as $u)
                        <li>
                            <div class="meta">{{ $u->created_at->format('M j, Y g:i A') }} · {{ $u->user?->present()->name() ?? 'System' }} @if ($u->is_customer_visible)· <span title="Visible in client portal">👁 customer</span>@endif</div>
                            @if ($u->to_status)<strong>{{ config('pcfix.repair_statuses')[$u->to_status] ?? $u->to_status }}</strong>@else<strong>{{ ucfirst(str_replace('_', ' ', $u->type)) }}</strong>@endif
                            @if ($u->note)<div class="note">{{ $u->note }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        @if ($history->count())
        <div class="card">
            <div class="card-head"><h2>Device repair history</h2></div>
            <div class="card-body">
                @foreach ($history as $h)
                    <div style="padding:4px 0"><a href="{{ route('ops.repair_jobs.show', $h->hashed_id) }}">{{ $h->job_number }}</a> {!! OpsUi::badge($h->status, $h->statusLabel()) !!}<br><small>{{ $h->created_at->format('M j, Y') }} · {{ \Illuminate\Support\Str::limit($h->reported_issue, 60) }}</small></div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
