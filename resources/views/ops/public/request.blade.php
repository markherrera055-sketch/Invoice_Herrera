<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request a repair · {{ $company->present()->name() }}</title>
    <link rel="stylesheet" href="{{ asset('css/pcfix-ops.css') }}?v=1">
</head>
<body>
<div class="public-wrap">
    <div class="brand" style="color:var(--text);padding:0 0 16px">
        <span class="brand-mark" aria-hidden="true">PC</span>
        <span>{{ $company->present()->name() }}<small style="color:var(--muted)">Computer repair &amp; IT support</small></span>
    </div>
    <div class="card">
        <div class="card-head"><div><h1>Request a repair or IT service</h1><p class="muted" style="margin:4px 0 0">Tell us about your device. We will review it and send you a quotation to approve online.</p></div></div>
        <div class="card-body">
            @if ($errors->any())
                <div class="alert alert-error" role="alert"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif
            <form method="POST" action="{{ route('ops.public.store', $company->company_key) }}">
                @csrf
                @include('ops::partials.request_fields', ['sr' => null, 'withCustomer' => true, 'staff' => false])
                <p class="muted" style="font-size:12.5px">By submitting you agree that we may contact you about this request. Your information is used only to process your repair.</p>
                <button class="btn btn-primary">Submit request</button>
            </form>
        </div>
    </div>
    <p class="muted" style="text-align:center;font-size:12.5px">Already a customer? <a href="{{ route('client.login') }}">Sign in to the client portal</a> to track repairs and approve quotations.</p>
</div>
</body>
</html>
