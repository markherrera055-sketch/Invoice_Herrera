@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', 'Purchase Records')

@section('content')
<div class="page-head"><div><h1>Purchase records</h1><p>Purchase orders from Invoice Ninja's existing Purchase Orders module.</p></div>
    <a class="btn" href="{{ OpsController::adminUrl('purchase_orders') }}" target="_blank" rel="noopener">Manage in Invoice Ninja ↗</a></div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th class="num">Amount</th><th>Status</th><th>From request</th></tr></thead>
        <tbody>
        @php $labels = [1 => 'Draft', 2 => 'Sent', 3 => 'Accepted', 4 => 'Received', 5 => 'Cancelled']; @endphp
        @forelse ($orders as $po)
            <tr>
                <td><a class="row-link" href="{{ OpsController::adminUrl('purchase_orders/' . $po->hashed_id . '/edit') }}" target="_blank" rel="noopener">{{ $po->number ?: 'Draft' }}</a></td>
                <td>{{ $po->vendor?->name }}</td>
                <td>{{ $po->date ? \Illuminate\Support\Carbon::parse($po->date)->format('M j, Y') : '—' }}</td>
                <td class="num">{{ OpsUi::money($po->amount) }}</td>
                <td><span class="badge {{ $po->status_id == 4 ? 'b-good' : ($po->status_id == 5 ? '' : 'b-info') }}">{{ $labels[$po->status_id] ?? '-' }}</span></td>
                <td>{{ $linked[$po->id] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No purchase orders.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $orders->links('ops::partials.pagination') }}
</div>
@endsection
