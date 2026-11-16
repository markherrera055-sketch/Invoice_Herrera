@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Low Stock')

@section('content')
<div class="page-head"><div><h1>Low stock</h1><p>Items at or below their minimum level. A purchase request is raised automatically the first time an item drops low.</p></div></div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>SKU</th><th>Item</th><th>Supplier</th><th class="num">On hand</th><th class="num">Minimum</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $p)
            <tr>
                <td><a class="row-link mono" href="{{ route('ops.inventory.show', $p->hashed_id) }}">{{ $p->product_key }}</a></td>
                <td>{{ $p->notes }}</td>
                <td>{{ $p->vendor?->name ?? '—' }}</td>
                <td class="num">{{ $p->in_stock_quantity }}</td>
                <td class="num">{{ $p->stock_notification_threshold }}</td>
                <td>{!! $p->in_stock_quantity <= 0 ? '<span class="badge b-bad">Out of stock</span>' : '<span class="badge b-warn">Low</span>' !!}</td>
                <td>@if (in_array('purchase_requests.create', $opsAbilities))<a class="btn btn-sm" href="{{ route('ops.purchase_requests.create', ['product' => $p->hashed_id]) }}">Request purchase</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">All tracked items are above their minimum level.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
