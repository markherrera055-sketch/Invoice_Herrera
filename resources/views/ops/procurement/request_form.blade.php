@extends('ops::layouts.app')
@use('App\Models\Ops\PurchaseRequest')
@section('title', 'New Purchase Request')

@section('content')
<div class="page-head"><div><h1>New purchase request</h1><p>Spare parts, repair materials, equipment or software licenses. A manager must approve before ordering.</p></div></div>
<form method="POST" action="{{ route('ops.purchase_requests.store') }}" class="card">
    @csrf
    <div class="card-body">
        <div class="form-row">
            <div class="field"><label for="type">Request type</label><select id="type" name="type">@foreach (PurchaseRequest::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label for="vendor">Preferred supplier</label><select id="vendor" name="vendor"><option value="">Decide later</option>@foreach ($vendors as $v)<option value="{{ $v->hashed_id }}" @selected($preselect && $preselect->vendor_id === $v->id)>{{ $v->name }}</option>@endforeach</select></div>
        </div>
        <div class="field"><label for="justification">Justification *</label><textarea id="justification" name="justification" required minlength="5" maxlength="2000" placeholder="Why is this needed? e.g. stock below minimum, license for new technician">{{ old('justification', $preselect ? "Restock {$preselect->product_key} (on hand: {$preselect->in_stock_quantity})." : '') }}</textarea></div>
        <label>Items</label>
        <div id="pr-items">
            @for ($i = 0; $i < 3; $i++)
            <div class="lines"><div class="line" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1.6fr) 90px 120px">
                <select name="items[{{ $i }}][product]" aria-label="Inventory item">
                    <option value="">Not an inventory item</option>
                    @foreach ($products as $p)<option value="{{ $p->hashed_id }}" @selected($i === 0 && $preselect?->id === $p->id)>{{ $p->product_key }} (stock {{ $p->in_stock_quantity }})</option>@endforeach
                </select>
                <input class="desc" type="text" name="items[{{ $i }}][description]" maxlength="255" placeholder="Description (e.g. Windows 11 Pro license)" value="{{ $i === 0 && $preselect ? $preselect->notes : '' }}" aria-label="Description">
                <input type="number" name="items[{{ $i }}][quantity]" min="0" placeholder="Qty" value="{{ $i === 0 && $preselect ? max(1, $preselect->stock_notification_threshold * 2 - $preselect->in_stock_quantity) : '' }}" aria-label="Quantity">
                <input type="number" name="items[{{ $i }}][unit_cost]" min="0" step="0.01" placeholder="Unit cost" value="{{ $i === 0 && $preselect ? (float) $preselect->cost : '' }}" aria-label="Unit cost">
            </div></div>
            @endfor
        </div>
        <small class="muted">Rows without a quantity are ignored. Unit cost defaults to the item's last cost.</small>
        <div class="mt"><button class="btn btn-primary">Submit for approval</button> <a class="btn" href="{{ route('ops.purchase_requests.index') }}">Cancel</a></div>
    </div>
</form>
@endsection
