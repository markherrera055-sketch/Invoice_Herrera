@extends('portal.ninja2020.layout.app')
@section('meta_title', 'Request a Repair')
@push('head')<link rel="stylesheet" href="{{ asset('css/pcfix-portal.css') }}?v=1">@endpush

@section('body')
<div class="pcx">
    <div class="head"><div><h1>Request a repair</h1><p>We already have your contact details ({{ $contact->email }}).</p></div></div>
    @if ($errors->any())
        <div class="alert alert-error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    <form method="POST" action="{{ route('client.ops.service_requests.store') }}" class="card">
        @csrf
        <div class="card-body">
            @include('ops::partials.request_fields', ['sr' => null, 'withCustomer' => false, 'staff' => false])
            <button class="btn btn-primary">Submit request</button>
            <a class="btn" href="{{ route('client.ops.service_requests.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
