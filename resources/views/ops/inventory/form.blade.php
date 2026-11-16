@extends('ops::layouts.app')
@section('title', $item ? 'Edit ' . $item->product_key : 'New Inventory Item')

@section('content')
@php $v = fn ($k, $d = '') => old($k, $item->{$k} ?? $d); @endphp
<div class="page-head"><div><h1>{{ $item ? 'Edit ' . $item->product_key : 'New inventory item' }}</h1>
    <p>{{ $item ? 'Quantity changes go through stock adjustments so every change is logged.' : 'Creates an Invoice Ninja product with stock tracking enabled.' }}</p></div></div>
<form method="POST" action="{{ $item ? route('ops.inventory.update', $item->hashed_id) : route('ops.inventory.store') }}" class="card">
    @csrf @if ($item) @method('PUT') @endif
    <div class="card-body">
        <div class="form-row">
            <div class="field"><label for="product_key">SKU / product key *</label><input id="product_key" type="text" name="product_key" value="{{ $v('product_key') }}" required maxlength="100"></div>
            <div class="field"><label for="category">Category</label><input id="category" type="text" name="category" value="{{ $v('category') }}" maxlength="100" placeholder="e.g. Storage, Memory, Display"></div>
            <div class="field"><label for="storage_location">Storage location</label><input id="storage_location" type="text" name="storage_location" value="{{ $v('storage_location') }}" maxlength="150" placeholder="e.g. HQ shelf B2"></div>
        </div>
        <div class="field"><label for="notes">Name / description</label><input id="notes" type="text" name="notes" value="{{ $v('notes') }}" maxlength="500"></div>
        <div class="form-row">
            <div class="field"><label for="cost">Unit cost *</label><input id="cost" type="number" name="cost" value="{{ $v('cost', 0) }}" min="0" step="0.01" required></div>
            <div class="field"><label for="price">Selling price *</label><input id="price" type="number" name="price" value="{{ $v('price', 0) }}" min="0" step="0.01" required></div>
            <div class="field"><label for="minimum">Minimum stock level *</label><input id="minimum" type="number" name="minimum" value="{{ old('minimum', $item->stock_notification_threshold ?? 2) }}" min="0" required></div>
            @unless ($item)<div class="field"><label for="opening_stock">Opening stock</label><input id="opening_stock" type="number" name="opening_stock" value="{{ old('opening_stock', 0) }}" min="0"></div>@endunless
        </div>
        <div class="field" style="max-width:360px"><label for="vendor">Supplier</label>
            <select id="vendor" name="vendor"><option value="">None</option>@foreach ($vendors as $vd)<option value="{{ $vd->hashed_id }}" @selected(($item->vendor_id ?? null) === $vd->id)>{{ $vd->name }}</option>@endforeach</select></div>
        <button class="btn btn-primary">{{ $item ? 'Save changes' : 'Create item' }}</button>
        <a class="btn" href="{{ $item ? route('ops.inventory.show', $item->hashed_id) : route('ops.inventory.index') }}">Cancel</a>
    </div>
</form>
@endsection
