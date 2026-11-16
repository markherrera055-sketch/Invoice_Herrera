@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Models\Ops\PurchaseRequest')
@section('title', 'Purchase Requests')

@section('content')
<div class="page-head">
    <div><h1>Purchase requests</h1><p>Low stock or need → request → manager approval → purchase order → stock received → inventory updated.</p></div>
    @if (in_array('purchase_requests.create', $opsAbilities))<a class="btn btn-primary" href="{{ route('ops.purchase_requests.create') }}">+ New request</a>@endif
</div>
<div class="tabs">
    <a href="{{ route('ops.purchase_requests.index') }}" class="{{ request('status') ? '' : 'active' }}">All</a>
    @foreach (PurchaseRequest::STATUSES as $k => $l)<a href="{{ route('ops.purchase_requests.index', ['status' => $k]) }}" class="{{ request('status') === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach
</div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Request</th><th>Type</th><th>Items</th><th>Supplier</th><th class="num">Est. total</th><th>Requested by</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($requests as $pr)
            <tr>
                <td><a class="row-link" href="{{ route('ops.purchase_requests.show', $pr->hashed_id) }}">{{ $pr->request_number }}</a>@if ($pr->source === 'low_stock')<br><span class="badge b-warn">Auto · low stock</span>@endif</td>
                <td>{{ PurchaseRequest::TYPES[$pr->type] ?? $pr->type }}</td>
                <td><small>{{ $pr->items->map(fn ($i) => $i->quantity . '× ' . \Illuminate\Support\Str::limit($i->description, 30))->implode(', ') }}</small></td>
                <td>{{ $pr->vendor?->name ?? '—' }}</td>
                <td class="num">{{ OpsUi::money($pr->total()) }}</td>
                <td>{{ $pr->requester?->present()->name() ?? 'System' }}<br><small>{{ $pr->created_at->format('M j, Y') }}</small></td>
                <td>{!! OpsUi::badge($pr->status, $pr->statusLabel()) !!}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No purchase requests.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $requests->links('ops::partials.pagination') }}
</div>
@endsection
