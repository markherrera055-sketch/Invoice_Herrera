@extends('portal.ninja2020.layout.app')
@use('App\Services\Ops\OpsUi')
@section('meta_title', $job->job_number)
@push('head')<link rel="stylesheet" href="{{ asset('css/pcfix-portal.css') }}?v=1">@endpush

@section('body')
<div class="pcx">
    <div class="head">
        <div><h1>Repair {{ $job->job_number }}</h1><p>{{ $job->device?->label() ?? 'Device' }} @if ($job->is_warranty_job)· Warranty repair (no charge)@endif</p></div>
        <div>{!! OpsUi::badge($job->status, $job->statusLabel()) !!}</div>
    </div>
    @include('ops::portal._progress', ['job' => $job, 'quotes' => $quotes, 'timeline' => $timeline])
    @if ($job->invoice || $job->warranty)
    <div class="card"><div class="card-body"><dl>
        @if ($job->invoice)<dt>Invoice</dt><dd><a href="{{ route('client.invoice.show', $job->invoice->hashed_id) }}">{{ $job->invoice->number }}</a></dd>@endif
        @if ($job->warranty)<dt>Warranty</dt><dd>{{ $job->warranty->warranty_number }}, valid until {{ $job->warranty->end_date->format('M j, Y') }}</dd>@endif
    </dl></div></div>
    @endif
    <a href="{{ route('client.ops.service_requests.index') }}">← All repairs</a>
</div>
@endsection
