@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', $vendor->name)

@section('content')
<div class="page-head"><div><h1>{{ $vendor->name }}</h1><p>{{ $vendor->number }} · {{ $vendor->phone }}</p></div>
    <a class="btn" href="{{ OpsController::adminUrl('vendors/' . $vendor->hashed_id) }}" target="_blank" rel="noopener">Open in Invoice Ninja ↗</a></div>
<div class="grid g-4 mb">
    <div class="card stat"><div class="label">Items supplied</div><div class="value">{{ $products->count() }}</div></div>
    <div class="card stat"><div class="label">Total expenses</div><div class="value">{{ OpsUi::money($expenseTotal) }}</div></div>
    <div class="card stat"><div class="label">Unpaid supplier bills</div><div class="value">{{ OpsUi::money($unpaidTotal) }}</div><div class="sub">Expenses without a payment date</div></div>
    <div class="card stat"><div class="label">Purchase orders</div><div class="value">{{ $orders->count() }}</div></div>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Purchase history</h2></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Request</th><th>Type</th><th class="num">Total</th><th>Status</th><th>PO</th></tr></thead>
                <tbody>
                @forelse ($requests as $r)
                    <tr><td><a href="{{ route('ops.purchase_requests.show', $r->hashed_id) }}">{{ $r->request_number }}</a></td><td>{{ \App\Models\Ops\PurchaseRequest::TYPES[$r->type] ?? $r->type }}</td><td class="num">{{ OpsUi::money($r->total()) }}</td><td>{!! OpsUi::badge($r->status, $r->statusLabel()) !!}</td><td>{{ $orders->firstWhere('id', $r->purchase_order_id)?->number ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No purchase requests for this supplier.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Supplier invoices &amp; expenses</h2></div>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Date</th><th>Expense</th><th>Category</th><th class="num">Amount</th><th>Paid</th></tr></thead>
                <tbody>
                @forelse ($expenses as $e)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($e->date)->format('M j, Y') }}</td><td>{{ $e->number }}<br><small>{{ \Illuminate\Support\Str::limit($e->public_notes, 60) }}</small></td><td>{{ $e->category?->name ?? '—' }}</td><td class="num">{{ OpsUi::money($e->amount) }}</td><td>{{ $e->payment_date ? 'Paid' : 'Unpaid' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No expenses recorded.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Items supplied</h2></div>
            <div class="card-body">
                @forelse ($products as $p)<a class="badge" href="{{ route('ops.inventory.show', $p->hashed_id) }}" style="margin:2px">{{ $p->product_key }} · {{ $p->in_stock_quantity }}</a>@empty <span class="muted">No inventory items linked.</span>@endforelse
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('ops.suppliers.update', $vendor->hashed_id) }}" class="card">
        @csrf @method('PUT')
        <div class="card-head"><h2>Supplier details</h2></div>
        <div class="card-body">
            @include('ops::procurement.supplier_fields', ['vendor' => $vendor, 'contact' => $vendor->contacts->first()])
            <button class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
@endsection
