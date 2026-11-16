@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Inventory')

@section('content')
@php $manage = in_array('inventory.manage', $opsAbilities); @endphp
<div class="page-head">
    <div><h1>Inventory items</h1><p>Spare parts and materials, stored as Invoice Ninja products with a full stock ledger.</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('ops.inventory.export') }}">Export stock report (CSV)</a>
        @if ($manage)<a class="btn btn-primary" href="{{ route('ops.inventory.create') }}">+ New item</a>@endif
    </div>
</div>
<div class="card">
    <div class="card-head">
        <form method="GET" class="filters">
            <div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="SKU or name" aria-label="Search"></div>
            <div class="field"><select name="category" aria-label="Category"><option value="">All categories</option>@foreach ($categories as $c)<option @selected(request('category') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div class="field"><select name="scope" aria-label="Scope"><option value="tracked">Stock-tracked items</option><option value="all" @selected(request('scope') === 'all')>All products ({{ $untracked }} untracked)</option></select></div>
            <button class="btn">Filter</button>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>SKU</th><th>Item</th><th>Category</th><th>Location</th><th>Supplier</th><th class="num">On hand</th><th class="num">Min.</th><th class="num">Unit cost</th><th class="num">Price</th></tr></thead>
        <tbody>
        @forelse ($items as $p)
            @php $low = $p->track_stock && $p->in_stock_quantity <= $p->stock_notification_threshold; @endphp
            <tr>
                <td><a class="row-link mono" href="{{ route('ops.inventory.show', $p->hashed_id) }}">{{ $p->product_key }}</a></td>
                <td>{{ \Illuminate\Support\Str::limit($p->notes, 60) }} @unless ($p->track_stock)<br><span class="badge">Not tracked</span>@endunless</td>
                <td>{{ $p->category ?: '—' }}</td>
                <td>{{ $p->storage_location ?: '—' }}</td>
                <td>{{ $p->vendor?->name ?? '—' }}</td>
                <td class="num">{{ $p->in_stock_quantity }} @if ($p->track_stock && $p->in_stock_quantity <= 0)<span class="badge b-bad">Out</span>@elseif ($low)<span class="badge b-warn">Low</span>@endif</td>
                <td class="num">{{ $p->stock_notification_threshold }}</td>
                <td class="num">{{ OpsUi::money($p->cost) }}</td>
                <td class="num">{{ OpsUi::money($p->price) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty">No inventory items yet.@if ($manage) <a href="{{ route('ops.inventory.create') }}">Add the first item</a> or enable tracking on an existing product.@endif</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $items->links('ops::partials.pagination') }}
</div>
@endsection
