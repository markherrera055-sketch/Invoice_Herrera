@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Repair Jobs')

@section('content')
@php $all = in_array('repair_jobs.view_all', $opsAbilities); @endphp
<div class="page-head">
    <div><h1>{{ $all ? 'Repair jobs' : 'My repair jobs' }}</h1><p>{{ $all ? 'Prioritised queue across all technicians.' : 'Jobs assigned to you, most urgent first.' }}</p></div>
</div>

<div class="tabs">
    <a href="{{ route('ops.repair_jobs.index', ['status' => 'open']) }}" class="{{ $status === 'open' ? 'active' : '' }}">Open</a>
    @foreach (config('pcfix.repair_statuses') as $k => $label)
        <a href="{{ route('ops.repair_jobs.index', array_filter(['status' => $k, 'technician' => request('technician')])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="card">
    <div class="card-head">
        <form method="GET" class="filters">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="field"><input type="search" name="q" value="{{ request('q') }}" placeholder="Job no., customer, serial" aria-label="Search"></div>
            <div class="field"><select name="priority" aria-label="Priority"><option value="">Any priority</option>@foreach (array_keys(config('pcfix.priorities')) as $p)<option value="{{ $p }}" @selected(request('priority') === $p)>{{ ucfirst($p) }}</option>@endforeach</select></div>
            @if ($all)
                <div class="field"><select name="technician" aria-label="Technician"><option value="">All technicians</option>@foreach ($technicians as $t)<option value="{{ $t->user_id }}" @selected((int) request('technician') === $t->user_id)>{{ $t->user?->present()->name() }}</option>@endforeach</select></div>
            @endif
            <button class="btn">Filter</button>
        </form>
    </div>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Job</th><th>Customer</th><th>Device / issue</th><th>Priority</th><th>Technician</th><th>Status</th><th>Age</th></tr></thead>
        <tbody>
        @forelse ($jobs as $job)
            <tr>
                <td><a class="row-link" href="{{ route('ops.repair_jobs.show', $job->hashed_id) }}">{{ $job->job_number }}</a>@if ($job->is_warranty_job)<br><span class="badge b-info">Warranty</span>@endif</td>
                <td>{{ $job->client?->present()->name() }}</td>
                <td>{{ $job->device?->label() ?? '—' }}<br><small>{{ \Illuminate\Support\Str::limit($job->reported_issue, 70) }}</small></td>
                <td>{!! OpsUi::priority($job->priority) !!}</td>
                <td>{{ $job->technician?->present()->name() ?? '—' }}@if ($job->assignment_mode)<br><small>{{ $job->assignment_mode === 'auto' ? 'Auto-assigned' : 'Manual' }}</small>@endif</td>
                <td>{!! OpsUi::badge($job->status, $job->statusLabel()) !!}</td>
                <td style="white-space:nowrap"><small>{{ $job->created_at->diffForHumans(null, true) }}</small></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No repair jobs in this view.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $jobs->links('ops::partials.pagination') }}
</div>
@endsection
