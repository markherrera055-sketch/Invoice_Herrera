@extends('ops::layouts.app')
@section('title', 'Suppliers')

@section('content')
<div class="page-head"><div><h1>Suppliers</h1><p>Central supplier database (Invoice Ninja vendors).</p></div><a class="btn btn-primary" href="{{ route('ops.suppliers.create') }}">+ New supplier</a></div>
<div class="card">
    <div class="card-head"><form method="GET" class="filters"><div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Name or email" aria-label="Search"></div><button class="btn">Search</button></form></div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th class="num">Items supplied</th><th class="num">Expenses</th></tr></thead>
        <tbody>
        @forelse ($vendors as $v)
            @php $c = $v->contacts->first(); @endphp
            <tr>
                <td><a class="row-link" href="{{ route('ops.suppliers.show', $v->hashed_id) }}">{{ $v->name }}</a><br><small>{{ $v->number }}</small></td>
                <td>{{ trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: '—' }}<br><small>{{ $c->email ?? '' }}</small></td>
                <td>{{ $v->phone ?: ($c->phone ?? '—') }}</td>
                <td class="num">{{ $productCounts[$v->id] ?? 0 }}</td>
                <td class="num">{{ $v->expense_count }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">No suppliers yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $vendors->links('ops::partials.pagination') }}
</div>
@endsection
