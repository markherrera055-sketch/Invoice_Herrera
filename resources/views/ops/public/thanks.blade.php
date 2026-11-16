<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request received · {{ $company->present()->name() }}</title>
    <link rel="stylesheet" href="{{ asset('css/pcfix-ops.css') }}?v=1">
</head>
<body>
<div class="center-page">
    <div class="card" style="max-width:520px;width:100%">
        <div class="card-body" style="text-align:center">
            <div style="font-size:40px" aria-hidden="true">✅</div>
            <h1>Thank you — request received</h1>
            <p>Your reference number is</p>
            <p class="mono" style="font-size:22px;font-weight:700">{{ $reference }}</p>
            <p class="muted">Our team will review your request and contact you with a quotation. Please keep this reference for follow-ups.</p>
            <a class="btn" href="{{ route('ops.public.create', $company->company_key) }}">Submit another request</a>
        </div>
    </div>
</div>
</body>
</html>
