@extends('ops::layouts.app')
@use('App\Models\Ops\InventoryMovement')
@section('title', 'Stock Movements')

@section('content')
<div class="page-head"><div><h1>Stock movements</h1><p>Every incoming, outgoing, repair usage and adjustment, with the resulting balance.</p></div>
    <a class="btn" href="{{ route('ops.inventory.export', array_filter(['report' => 'movements', 'type' => request('type'), 'from' => request('from'), 'to' => request('to')])) }}">Export movements (CSV)</a></div>
<div class="card">
    <div class="card-head">
        <form method="GET" class="filters">
            <div class="field"><label for="type">Type</label><select id="type" name="type"><option value="">All</option>@foreach (InventoryMovement::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn">Filter</button>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="num">Change</th><th class="num">Balance</th><th>Notes</th><th>By</th></tr></thead>
        <tbody>
        @forelse ($movements as $m)
            <tr>
                <td style="white-space:nowrap">{{ $m->created_at->format('M j, Y g:i A') }}</td>
                <td>@if ($m->product)<a class="mono" href="{{ route('ops.inventory.show', $m->product->hashed_id) }}">{{ $m->product->product_key }}</a>@endif</td>
                <td>{{ $m->typeLabel() }}</td>
                <td class="num">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                <td class="num">{{ $m->balance_after }}</td>
                <td>{{ $m->notes }}</td>
                <td>{{ $m->user?->present()->name() ?? 'System' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No movements match.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $movements->links('ops::partials.pagination') }}
</div>
@endsection
