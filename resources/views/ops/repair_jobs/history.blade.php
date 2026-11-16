@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Repair History')

@section('content')
<div class="page-head"><div><h1>Repair history</h1><p>Completed and cancelled jobs, searchable by job number, customer, serial number or model.</p></div></div>
<div class="card">
    <div class="card-head">
        <form method="GET" class="filters">
            <div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Job no., customer, serial, model" aria-label="Search"></div>
            <button class="btn">Search</button>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Job</th><th>Customer</th><th>Device</th><th>Technician</th><th>Status</th><th>Completed</th><th>Invoice</th></tr></thead>
        <tbody>
        @forelse ($jobs as $job)
            <tr>
                <td><a class="row-link" href="{{ route('ops.repair_jobs.show', $job->hashed_id) }}">{{ $job->job_number }}</a></td>
                <td>{{ $job->client?->present()->name() }}</td>
                <td>{{ $job->device?->label() }}<br><small class="mono">{{ $job->device?->serial_number }}</small></td>
                <td>{{ $job->technician?->present()->name() ?? '—' }}</td>
                <td>{!! OpsUi::badge($job->status, $job->statusLabel()) !!}</td>
                <td>{{ $job->completed_at?->format('M j, Y') ?? '—' }}</td>
                <td>{{ $job->invoice?->number ?? ($job->is_warranty_job ? 'No charge' : '—') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No matching repair history.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $jobs->links('ops::partials.pagination') }}
</div>
@endsection
