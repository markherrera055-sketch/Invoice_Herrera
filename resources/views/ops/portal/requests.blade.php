@extends('portal.ninja2020.layout.app')
@use('App\Services\Ops\OpsUi')
@section('meta_title', 'Service Requests')
@push('head')<link rel="stylesheet" href="{{ asset('css/pcfix-portal.css') }}?v=1">@endpush

@section('body')
<div class="pcx">
    <div class="head">
        <div><h1>Repairs &amp; service requests</h1><p>Track your devices from request to completion.</p></div>
        <a class="btn btn-primary" href="{{ route('client.ops.service_requests.create') }}">Request a repair</a>
    </div>
    <div class="card">
        <div class="wrap"><table>
            <thead><tr><th>Reference</th><th>Device</th><th>Submitted</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($requests as $sr)
                <tr>
                    <td><a href="{{ route('client.ops.service_requests.show', $sr->hashed_id) }}">{{ $sr->request_number }}</a></td>
                    <td>{{ $sr->device_type }} · {{ $sr->device_brand }} {{ $sr->device_model }}</td>
                    <td>{{ $sr->created_at->format('M j, Y') }}</td>
                    <td>@if ($sr->repairJob){!! OpsUi::badge($sr->repairJob->status, $sr->repairJob->statusLabel()) !!}@else{!! OpsUi::badge($sr->status, $sr->statusLabel()) !!}@endif</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted" style="text-align:center;padding:28px">No requests yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    @if ($jobs->count())
    <div class="card">
        <div class="card-head"><h2>Other repairs</h2></div>
        <div class="wrap"><table>
            <thead><tr><th>Job</th><th>Device</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($jobs as $job)
                <tr><td><a href="{{ route('client.ops.repairs.show', $job->hashed_id) }}">{{ $job->job_number }}</a></td><td>{{ $job->device?->label() }}</td><td>{!! OpsUi::badge($job->status, $job->statusLabel()) !!}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    @endif
    {{ $requests->links() }}
</div>
@endsection
