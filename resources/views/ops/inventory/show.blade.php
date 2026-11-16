@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', $item->product_key)

@section('content')
@php $manage = in_array('inventory.manage', $opsAbilities); $low = $item->track_stock && $item->in_stock_quantity <= $item->stock_notification_threshold; @endphp
<div class="page-head">
    <div><h1><span class="mono">{{ $item->product_key }}</span> @if ($item->in_stock_quantity <= 0 && $item->track_stock)<span class="badge b-bad">Out of stock</span>@elseif ($low)<span class="badge b-warn">Low stock</span>@endif</h1><p>{{ $item->notes }}</p></div>
    <div class="actions">
        @if ($manage)<a class="btn" href="{{ route('ops.inventory.edit', $item->hashed_id) }}">Edit</a>@endif
        @if (in_array('purchase_requests.create', $opsAbilities))<a class="btn" href="{{ route('ops.purchase_requests.create', ['product' => $item->hashed_id]) }}">Request purchase</a>@endif
    </div>
</div>
<div class="grid g-4 mb">
    <div class="card stat"><div class="label">On hand</div><div class="value">{{ $item->in_stock_quantity }}</div><div class="sub">Minimum {{ $item->stock_notification_threshold }}</div></div>
    <div class="card stat"><div class="label">Unit cost</div><div class="value">{{ OpsUi::money($item->cost) }}</div><div class="sub">Stock value {{ OpsUi::money(max(0, $item->in_stock_quantity) * $item->cost) }}</div></div>
    <div class="card stat"><div class="label">Selling price</div><div class="value">{{ OpsUi::money($item->price) }}</div></div>
    <div class="card stat"><div class="label">Supplier</div><div class="value" style="font-size:16px">@if ($item->vendor)<a href="{{ route('ops.suppliers.show', $item->vendor->hashed_id) }}">{{ $item->vendor->name }}</a>@else — @endif</div><div class="sub">{{ $item->category }} · {{ $item->storage_location }}</div></div>
</div>
<div class="grid g-main">
    <div class="card">
        <div class="card-head"><h2>Stock movement history</h2></div>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Date</th><th>Type</th><th class="num">Change</th><th class="num">Balance</th><th>Reference / notes</th><th>By</th></tr></thead>
            <tbody>
            @forelse ($movements as $m)
                <tr>
                    <td style="white-space:nowrap">{{ $m->created_at->format('M j, Y g:i A') }}</td>
                    <td>{{ $m->typeLabel() }}</td>
                    <td class="num">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                    <td class="num">{{ $m->balance_after }}</td>
                    <td>{{ $m->notes }}</td>
                    <td>{{ $m->user?->present()->name() ?? 'System' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No movements recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $movements->links('ops::partials.pagination') }}
    </div>
    @if ($manage)
    <div class="stack">
        <form method="POST" action="{{ route('ops.inventory.adjust', $item->hashed_id) }}" class="card">
            @csrf
            <div class="card-head"><h2>Adjust stock</h2></div>
            <div class="card-body">
                <div class="field"><label for="mode">Movement</label><select id="mode" name="mode"><option value="stock_in">Stock in (received)</option><option value="stock_out">Stock out (used / damaged)</option><option value="count">Physical count (set exact quantity)</option></select></div>
                <div class="form-row">
                    <div class="field"><label for="quantity">Quantity</label><input id="quantity" type="number" name="quantity" min="0" required></div>
                    <div class="field"><label for="unit_cost">Unit cost (stock in)</label><input id="unit_cost" type="number" name="unit_cost" min="0" step="0.01"></div>
                </div>
                <div class="field"><label for="adj-notes">Reason *</label><input id="adj-notes" type="text" name="notes" required minlength="3" maxlength="500"></div>
                <button class="btn btn-primary">Record movement</button>
            </div>
        </form>
        <form method="POST" action="{{ route('ops.inventory.archive', $item->hashed_id) }}" class="card card-body" data-confirm="Stop tracking stock for this item? The product stays in Invoice Ninja.">
            @csrf <button class="btn btn-danger btn-sm">Archive from inventory</button>
        </form>
    </div>
    @endif
</div>
@endsection
