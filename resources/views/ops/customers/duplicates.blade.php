@extends('ops::layouts.app')
@use('App\Http\Controllers\Ops\OpsController')
@section('title', 'Duplicate Customers')

@section('content')
<div class="page-head">
    <div><h1>Possible duplicate customer profiles</h1><p>Profiles sharing an email, phone number or identical name. Merge them in Invoice Ninja (Client → Merge), which keeps invoices and payments.</p></div>
    <a class="btn" href="{{ route('ops.customers.index') }}">Back to customers</a>
</div>
@forelse ($groups as $g)
    <div class="card mb">
        <div class="card-head"><h2>{{ count($g['clients']) }} profiles</h2>
            <span>@foreach (array_unique($g['reasons']) as $r)<span class="badge b-warn" style="margin-left:4px">Same {{ \Illuminate\Support\Str::before($r, ':') }}: {{ \Illuminate\Support\Str::after($r, ':') }}</span>@endforeach</span></div>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Customer</th><th>Contact</th><th>Created</th><th></th></tr></thead>
            <tbody>
            @foreach ($g['clients'] as $c)
                <tr><td><a href="{{ route('ops.customers.show', $c->hashed_id) }}">{{ $c->present()->name() }}</a> <small>#{{ $c->number }}</small></td>
                    <td>{{ $c->contacts->first()?->email }} {{ $c->contacts->first()?->phone }}</td>
                    <td>{{ $c->created_at?->format('M j, Y') }}</td>
                    <td><a href="{{ OpsController::adminUrl('clients/' . $c->hashed_id) }}" target="_blank" rel="noopener">Open in Invoice Ninja ↗</a></td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@empty
    <div class="card card-body empty">No duplicate profiles found.</div>
@endforelse
@endsection
