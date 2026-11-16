@extends('ops::layouts.app')
@section('title', 'New Service Request')

@section('content')
<div class="page-head">
    <div><h1>New service request</h1><p>Record a phone, walk-in or email request with the same standard form customers use online.</p></div>
</div>
<form method="POST" action="{{ route('ops.service_requests.store') }}" class="card">
    @csrf
    <div class="card-body">
        <div class="field" style="max-width:260px"><label for="channel">Received via</label>
            <select id="channel" name="channel">
                <option value="walk_in">Walk-in</option><option value="phone">Phone</option><option value="email">Email</option>
            </select>
        </div>
        @include('ops::partials.request_fields', ['sr' => null, 'withCustomer' => true, 'staff' => true])
        <div class="alert alert-info">On save the system checks for existing customers by email, phone number and name, and links an exact match automatically. Any other similar records are listed for your review.</div>
        <button class="btn btn-primary">Create request</button>
        <a class="btn" href="{{ route('ops.service_requests.index') }}">Cancel</a>
    </div>
</form>
@endsection
