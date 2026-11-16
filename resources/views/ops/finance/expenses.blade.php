@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', 'Expenses')

@section('content')
@php
    $byMonth = collect($summary)->groupBy('month');
    $byCategory = collect($summary)->groupBy('category')->map(fn ($r) => ['label' => $r->first()['category'], 'value' => round($r->sum('amount'))])->sortByDesc('value')->values()->all();
@endphp
<div class="page-head">
    <div><h1>Expenses</h1><p>Invoice Ninja expenses, including supplier invoices recorded from purchase requests.</p></div>
    <a class="btn btn-primary" href="{{ OpsController::adminUrl('expenses/create') }}" target="_blank" rel="noopener">+ New expense in Invoice Ninja ↗</a>
</div>
<div class="card card-body mb">@include('ops::partials.period_filter')</div>

<div class="grid g-2 mb">
    <div class="card">
        <div class="card-head"><h2>By category</h2><strong>{{ OpsUi::money(collect($summary)->sum('amount')) }}</strong></div>
        <div class="card-body">@include('ops::partials.hbars', ['items' => $byCategory, 'unit' => $metrics->currencyCode(), 'empty' => 'No expenses in this period.'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Monthly summary</h2></div>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Month</th><th>Top category</th><th class="num">Count</th><th class="num">Total</th></tr></thead>
            <tbody>
            @forelse ($byMonth as $month => $rows)
                <tr><td>{{ \Illuminate\Support\Carbon::parse($month . '-01')->format('F Y') }}</td><td>{{ $rows->first()['category'] }}</td><td class="num">{{ $rows->sum('count') }}</td><td class="num">{{ OpsUi::money($rows->sum('amount')) }}</td></tr>
            @empty
                <tr><td colspan="4" class="empty">No expenses in this period.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Expense records</h2>
        <form method="GET" class="filters">
            @foreach (request()->only(['period', 'from', 'to']) as $k => $val)<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endforeach
            <div class="field"><select name="category" aria-label="Category" onchange="this.form.submit()"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Date</th><th>Expense</th><th>Supplier</th><th>Category</th><th class="num">Amount</th><th>Paid</th></tr></thead>
        <tbody>
        @forelse ($expenses as $e)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($e->date)->format('M j, Y') }}</td>
                <td><a href="{{ OpsController::adminUrl('expenses/' . $e->hashed_id . '/edit') }}" target="_blank" rel="noopener">{{ $e->number }}</a><br><small>{{ \Illuminate\Support\Str::limit($e->public_notes, 70) }}</small></td>
                <td>@if ($e->vendor)<a href="{{ route('ops.suppliers.show', $e->vendor->hashed_id) }}">{{ $e->vendor->name }}</a>@else — @endif</td>
                <td>{{ $e->category?->name ?? 'Uncategorised' }}</td>
                <td class="num">{{ OpsUi::money($e->amount, $e->currency_id ?: null) }}</td>
                <td>{{ $e->payment_date ? \Illuminate\Support\Carbon::parse($e->payment_date)->format('M j') : 'Unpaid' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No expenses in this period.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $expenses->links('ops::partials.pagination') }}
</div>
@endsection
