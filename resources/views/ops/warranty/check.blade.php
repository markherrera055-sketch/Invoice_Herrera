@extends('ops::layouts.app')
@section('title', 'Warranty Check')

@section('content')
<div class="page-head"><div><h1>Warranty check: <span class="mono">{{ $serial }}</span></h1><p>Automatic eligibility by device serial number.</p></div>
    <a class="btn" href="{{ route('ops.warranty.index') }}">Back to warranties</a></div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Customer</th><th>Result</th><th>Warranty</th><th>Valid until</th></tr></thead>
        <tbody>
        @forelse ($results as $r)
            <tr><td>{{ $r['client'] }}</td>
                <td>{!! $r['eligible'] ? '<span class="badge b-good">Covered</span>' : '<span class="badge b-bad">Not covered</span>' !!}<br><small>{{ $r['reason'] }}</small></td>
                <td>{{ $r['warranty_number'] ?? '—' }}</td><td>{{ $r['end_date'] ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="4" class="empty">No warranty is registered for this serial number.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
