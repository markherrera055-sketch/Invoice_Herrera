@extends('portal.ninja2020.layout.app')
@use('App\Services\Ops\OpsUi')
@section('meta_title', $sr->request_number)
@push('head')<link rel="stylesheet" href="{{ asset('css/pcfix-portal.css') }}?v=1">@endpush

@section('body')
<div class="pcx">
    @if (session('message'))<div class="alert">{{ session('message') }}</div>@endif
    <div class="head">
        <div><h1>{{ $sr->request_number }}</h1><p>{{ $sr->device_type }} · {{ $sr->device_brand }} {{ $sr->device_model }} · submitted {{ $sr->created_at->format('M j, Y') }}</p></div>
        <div>@if ($job){!! OpsUi::badge($job->status, $job->statusLabel()) !!}@else{!! OpsUi::badge($sr->status, $sr->statusLabel()) !!}@endif</div>
    </div>

    @include('ops::portal._progress', ['job' => $job, 'quotes' => $quotes, 'timeline' => $timeline])

    <div class="card">
        <div class="card-head"><h2>Your request</h2></div>
        <div class="card-body">
            <dl>
                <dt>Problem</dt><dd style="white-space:pre-line">{{ $sr->reported_issue }}</dd>
                <dt>Serial number</dt><dd>{{ $sr->serial_number ?: '—' }}</dd>
                <dt>Preferred date</dt><dd>{{ $sr->preferred_service_date?->format('M j, Y') ?? '—' }}</dd>
                <dt>Branch</dt><dd>{{ $sr->branch ?: 'No preference' }}</dd>
                @if ($job?->invoice)<dt>Invoice</dt><dd><a href="{{ route('client.invoice.show', $job->invoice->hashed_id) }}">{{ $job->invoice->number }}</a></dd>@endif
            </dl>
        </div>
    </div>
    <a href="{{ route('client.ops.service_requests.index') }}">← All requests</a>
</div>
@endsection
