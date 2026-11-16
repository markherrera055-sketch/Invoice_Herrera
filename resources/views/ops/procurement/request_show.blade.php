@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\PurchaseRequest')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', $pr->request_number)

@section('content')
@php
    $canApprove = in_array('purchase_requests.approve', $opsAbilities);
    $canReceive = in_array('purchase_requests.receive', $opsAbilities);
    $canExpense = in_array('expenses.manage', $opsAbilities);
@endphp
<div class="page-head">
    <div><h1>{{ $pr->request_number }} {!! OpsUi::badge($pr->status, $pr->statusLabel()) !!}</h1>
        <p>{{ PurchaseRequest::TYPES[$pr->type] ?? $pr->type }} · requested by {{ $pr->requester?->present()->name() ?? 'System (low stock)' }} on {{ $pr->created_at->format('M j, Y') }}</p></div>
</div>

<ol class="steps card card-body mb">
    @php $at = ['pending' => 0, 'approved' => 1, 'ordered' => 2, 'received' => 3, 'rejected' => -1, 'cancelled' => -1][$pr->status] ?? 0; @endphp
    @foreach (['Requested', 'Approved', 'Ordered', 'Received'] as $i => $s)<li class="{{ $at >= 0 && $i <= $at ? 'done' : '' }}">{{ $s }}</li>@endforeach
</ol>

<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Items</h2><strong>{{ OpsUi::money($pr->total()) }}</strong></div>
            <form method="POST" action="{{ route('ops.purchase_requests.receive', $pr->hashed_id) }}">
                @csrf
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Received</th>@if ($canReceive && in_array($pr->status, ['approved', 'ordered']))<th>Receive now</th>@endif</tr></thead>
                    <tbody>
                    @foreach ($pr->items as $item)
                        <tr>
                            <td>{{ $item->description }}@if ($item->product)<br><a class="mono" href="{{ route('ops.inventory.show', $item->product->hashed_id) }}">{{ $item->product->product_key }}</a> <small>(stock {{ $item->product->in_stock_quantity }})</small>@endif</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ OpsUi::money($item->unit_cost) }}</td>
                            <td class="num">{{ $item->received_quantity }}</td>
                            @if ($canReceive && in_array($pr->status, ['approved', 'ordered']))
                                <td><input type="number" name="received[{{ $item->id }}]" min="0" max="{{ $item->quantity - $item->received_quantity }}" value="{{ $item->quantity - $item->received_quantity }}" style="max-width:100px" aria-label="Quantity received"></td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @if ($canReceive && in_array($pr->status, ['approved', 'ordered']))
                    <div class="card-body" style="border-top:1px solid var(--border)"><button class="btn btn-primary">Receive stock &amp; update inventory</button></div>
                @endif
            </form>
        </div>
        <div class="card">
            <div class="card-head"><h2>Details</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Justification</dt><dd style="white-space:pre-line">{{ $pr->justification }}</dd>
                    <dt>Supplier</dt><dd>@if ($pr->vendor)<a href="{{ route('ops.suppliers.show', $pr->vendor->hashed_id) }}">{{ $pr->vendor->name }}</a>@else — @endif</dd>
                    <dt>Decision</dt><dd>@if ($pr->approved_at){{ $pr->approver?->present()->name() }} · {{ $pr->approved_at->format('M j, Y g:i A') }}<br><small>{{ $pr->decision_notes }}</small>@else Pending @endif</dd>
                    <dt>Purchase order</dt><dd>@if ($pr->purchaseOrder)<a href="{{ OpsController::adminUrl('purchase_orders/' . $pr->purchaseOrder->hashed_id . '/edit') }}" target="_blank" rel="noopener">{{ $pr->purchaseOrder->number ?: 'Draft' }} ↗</a>@else — @endif</dd>
                    <dt>Expense</dt><dd>{{ $pr->expense ? $pr->expense->number . ' · ' . OpsUi::money($pr->expense->amount) : '—' }}</dd>
                    <dt>Received</dt><dd>{{ $pr->received_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="stack">
        @if ($canApprove && $pr->status === 'pending')
        <form method="POST" action="{{ route('ops.purchase_requests.decide', $pr->hashed_id) }}" class="card">
            @csrf
            <div class="card-head"><h2>Management approval</h2></div>
            <div class="card-body">
                <div class="field"><label for="notes">Notes (required when rejecting)</label><textarea id="notes" name="notes" maxlength="1000"></textarea></div>
                <div class="actions"><button class="btn btn-primary" name="decision" value="approve">Approve</button><button class="btn btn-danger" name="decision" value="reject">Reject</button></div>
            </div>
        </form>
        @endif

        @if ($canReceive && $pr->status === 'approved')
        <form method="POST" action="{{ route('ops.purchase_requests.order', $pr->hashed_id) }}" class="card">
            @csrf
            <div class="card-head"><h2>Place order</h2></div>
            <div class="card-body">
                @unless ($pr->vendor_id)
                    <div class="field"><label for="po-vendor">Supplier</label><select id="po-vendor" name="vendor" required><option value="">Select…</option>@foreach (\App\Models\Vendor::query()->where('company_id', $opsCompany->id)->where('is_deleted', false)->orderBy('name')->get() as $v)<option value="{{ $v->hashed_id }}">{{ $v->name }}</option>@endforeach</select></div>
                @endunless
                <p class="muted" style="margin-top:0">Creates a purchase order in Invoice Ninja's Purchase Orders module.</p>
                <button class="btn btn-primary">Create purchase order</button>
            </div>
        </form>
        @endif

        @if ($canExpense && ! $pr->expense_id && in_array($pr->status, ['approved', 'ordered', 'received']))
        <form method="POST" action="{{ route('ops.purchase_requests.expense', $pr->hashed_id) }}" class="card">
            @csrf
            <div class="card-head"><h2>Record supplier invoice</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="field"><label for="amount">Amount</label><input id="amount" type="number" name="amount" min="0" step="0.01" value="{{ $pr->total() }}" required></div>
                    <div class="field"><label for="date">Invoice date</label><input id="date" type="date" name="date" value="{{ now()->toDateString() }}" required></div>
                </div>
                <div class="field"><label for="category_id">Expense category</label><select id="category_id" name="category_id"><option value="">Uncategorised</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                <div class="field"><label for="reference">Supplier invoice no.</label><input id="reference" type="text" name="reference" maxlength="100"></div>
                <button class="btn">Record expense in Invoice Ninja</button>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection
