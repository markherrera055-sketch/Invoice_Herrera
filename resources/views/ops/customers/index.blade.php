@extends('ops::layouts.app')
@section('title', 'Customers')

@section('content')
<div class="page-head">
    <div><h1>Customers</h1><p>Invoice Ninja clients with their repair activity.</p></div>
    <div class="actions"><a class="btn" href="{{ route('ops.customers.index', ['view' => 'duplicates']) }}">Find duplicate profiles</a></div>
</div>
<div class="card">
    <div class="card-head"><form method="GET" class="filters"><div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Name, email or phone" aria-label="Search"></div><button class="btn">Search</button></form></div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Customer</th><th>Primary contact</th><th>Phone</th><th class="num">Repair jobs</th></tr></thead>
        <tbody>
        @forelse ($clients as $c)
            @php $pc = $c->contacts->first(); @endphp
            <tr>
                <td><a class="row-link" href="{{ route('ops.customers.show', $c->hashed_id) }}">{{ $c->present()->name() }}</a><br><small>#{{ $c->number }}</small></td>
                <td>{{ trim(($pc->first_name ?? '') . ' ' . ($pc->last_name ?? '')) }}<br><small>{{ $pc->email ?? '' }}</small></td>
                <td>{{ $c->phone ?: ($pc->phone ?? '—') }}</td>
                <td class="num">{{ $jobCounts[$c->id] ?? 0 }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">No customers match.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $clients->links('ops::partials.pagination') }}
</div>
@endsection
