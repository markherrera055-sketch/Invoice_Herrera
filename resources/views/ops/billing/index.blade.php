@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', 'Billing & Payments')

@section('content')
@php $manage = in_array('billing.manage', $opsAbilities); @endphp
<div class="page-head">
    <div><h1>Billing &amp; payments</h1><p>Repair completed → completion verified → invoice generated → customer notified → payment received → status updated → receipt issued.</p></div>
    <a class="btn" href="{{ OpsController::adminUrl('payments') }}" target="_blank" rel="noopener">Record payment in Invoice Ninja ↗</a>
</div>

<div class="card mb">
    <div class="card-head"><h2>Ready to invoice <span class="badge">{{ $ready->count() }}</span></h2><small>Completed repair jobs awaiting verification</small></div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Job</th><th>Customer</th><th>Technician</th><th>Completed</th><th class="num">Approved quotes</th><th>Action</th></tr></thead>
        <tbody>
        @forelse ($ready as $row)
            @php $job = $row['job']; @endphp
            <tr>
                <td><a class="row-link" href="{{ route('ops.repair_jobs.show', $job->hashed_id) }}">{{ $job->job_number }}</a>@if ($job->is_warranty_job)<br><span class="badge b-info">Warranty</span>@endif</td>
                <td>{{ $job->client?->present()->name() }}<br><small>{{ $job->device?->label() }}</small></td>
                <td>{{ $job->technician?->present()->name() }}</td>
                <td>{{ $job->completed_at?->format('M j, g:i A') }}<br><small>{{ $job->completed_at?->diffForHumans() }}</small></td>
                <td class="num">{{ $job->is_warranty_job ? 'Warranty' : OpsUi::money($row['quoted']) }}<br><small>{{ $row['quotes']->pluck('number')->filter()->implode(', ') }}</small></td>
                <td>
                    @if ($manage)
                    <form method="POST" action="{{ route('ops.billing.generate', $job->hashed_id) }}" data-confirm="Verify completion of {{ $job->job_number }} and generate the invoice?">
                        @csrf
                        @if ($row['unquoted'])
                            <label class="check" style="font-weight:400"><input type="checkbox" name="include_unquoted_parts" value="1">
                                Also bill parts not on an approved quote: {{ collect($row['unquoted'])->map(fn ($u) => $u['quantity'] . '× ' . $u['product_key'])->implode(', ') }}</label>
                        @endif
                        <input type="hidden" name="send" value="0">
                        <label class="check" style="font-weight:400"><input type="checkbox" name="send" value="1" checked> Email invoice to customer</label>
                        <button class="btn btn-primary btn-sm">Verify &amp; generate invoice</button>
                    </form>
                    @else <small>Waiting for accounting</small> @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nothing waiting. Completed jobs appear here automatically.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="grid g-4 mb">
    @foreach (['unpaid' => 'Unpaid (open)', 'partial' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid this month'] as $k => $label)
        <a class="card stat" href="{{ route('ops.billing.index', ['bucket' => $k]) }}" style="{{ $bucket === $k ? 'border-color:var(--brand)' : '' }}">
            <div class="label">{{ $label }}</div>
            <div class="value">{{ OpsUi::money($summary[$k]->s) }}</div>
            <div class="sub">{{ $summary[$k]->c }} invoice{{ $summary[$k]->c == 1 ? '' : 's' }}</div>
        </a>
    @endforeach
</div>

<div class="card">
    <div class="card-head">
        <h2>Payment monitoring</h2>
        <form method="GET" class="filters"><input type="hidden" name="bucket" value="{{ $bucket }}"><div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Invoice no. or customer" aria-label="Search"></div><button class="btn">Search</button></form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Invoice</th><th>Customer</th><th>Repair job</th><th>Due</th><th class="num">Amount</th><th class="num">Balance</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($invoices as $inv)
            <tr>
                <td><a class="row-link" href="{{ OpsController::adminUrl('invoices/' . $inv->hashed_id . '/edit') }}" target="_blank" rel="noopener">{{ $inv->number }}</a></td>
                <td>{{ $inv->client?->present()->name() }}</td>
                <td>{{ $repairInvoiceIds[$inv->id] ?? '—' }}</td>
                <td>{{ $inv->due_date ? \Illuminate\Support\Carbon::parse($inv->due_date)->format('M j, Y') : '—' }}</td>
                <td class="num">{{ OpsUi::money($inv->amount) }}</td>
                <td class="num">{{ OpsUi::money($inv->balance) }}</td>
                <td>{!! OpsUi::invoiceStatus($inv) !!}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No invoices in this view.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $invoices->links('ops::partials.pagination') }}
</div>

<div class="card mt">
    <div class="card-head"><h2>Automation status</h2><small>Configured in Invoice Ninja settings</small></div>
    <div class="card-body">
        <div class="grid g-4">
            <div>{!! $automation['email_reminders'] ? '<span class="badge b-good">On</span>' : '<span class="badge b-warn">Off</span>' !!} Email payment reminders</div>
            <div>{!! $automation['online_receipts'] ? '<span class="badge b-good">On</span>' : '<span class="badge b-warn">Off</span>' !!} Receipts for online payments</div>
            <div>{!! $automation['manual_receipts'] ? '<span class="badge b-good">On</span>' : '<span class="badge b-warn">Off</span>' !!} Receipts for manual payments</div>
            <div>{!! $automation['sms'] ? '<span class="badge b-good">On</span>' : '<span class="badge">Not configured</span>' !!} SMS overdue reminders</div>
        </div>
    </div>
</div>
@endsection
