@extends('ops::layouts.app')
@section('title', 'Audit Log')

@section('content')
<div class="page-head"><div><h1>Audit log</h1><p>Sensitive operations: logins, role changes, assignments, stock adjustments, approvals, invoicing.</p></div>
    <form method="GET" class="filters"><div class="field"><select name="action" aria-label="Action" onchange="this.form.submit()"><option value="">All actions</option>@foreach (['auth', 'user', 'service_request', 'repair_job', 'inventory', 'purchase_request', 'warranty', 'supplier', 'technician'] as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ ucwords(str_replace('_', ' ', $a)) }}</option>@endforeach</select></div></form></div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        @forelse ($logs as $l)
            <tr>
                <td style="white-space:nowrap">{{ $l->created_at->format('M j, Y g:i:s A') }}</td>
                <td>{{ $l->user?->present()->name() ?? ($l->client_contact_id ? 'Customer' : 'System') }}</td>
                <td class="mono">{{ $l->action }}</td>
                <td>{{ $l->subject_type }} #{{ $l->subject_id }}</td>
                <td><small class="mono" style="word-break:break-all">{{ \Illuminate\Support\Str::limit(json_encode($l->changes), 160) }}</small></td>
                <td><small>{{ $l->ip_address }}</small></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No audit entries.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $logs->links('ops::partials.pagination') }}
</div>
@endsection
