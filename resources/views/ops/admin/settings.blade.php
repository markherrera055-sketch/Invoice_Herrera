@extends('ops::layouts.app')
@use('App\Services\Ops\OpsUi')
@section('title', 'Settings')

@section('content')
<div class="page-head"><div><h1>Operations settings</h1><p>Values come from <span class="mono">config/pcfix.php</span> / <span class="mono">.env</span> and Invoice Ninja's company settings.</p></div></div>
<div class="grid g-2">
    <div class="card">
        <div class="card-head"><h2>Customer intake</h2></div>
        <div class="card-body">
            <label>Public service request form</label>
            <input type="text" value="{{ $publicUrl }}" readonly onclick="this.select()" aria-label="Public form URL">
            <p class="muted">Share this link on your website / Facebook page. Logged-in customers can also submit requests from the client portal.</p>
            <dl class="kv">
                <dt>Device types</dt><dd>{{ implode(', ', config('pcfix.device_types')) }}</dd>
                <dt>Branches</dt><dd>{{ implode(', ', config('pcfix.branches')) }}</dd>
                <dt>Duplicate guard</dt><dd>Identical submissions within {{ config('pcfix.defaults.duplicate_submission_minutes') }} minutes are merged</dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Billing &amp; warranty defaults</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Diagnostic fee</dt><dd>{{ OpsUi::money(config('pcfix.defaults.diagnostic_fee')) }} <small class="mono">PCFIX_DIAGNOSTIC_FEE</small></dd>
                <dt>Labor rate / hour</dt><dd>{{ OpsUi::money(config('pcfix.defaults.labor_rate')) }} <small class="mono">PCFIX_LABOR_RATE</small></dd>
                <dt>Warranty period</dt><dd>{{ config('pcfix.defaults.warranty_days') }} days <small class="mono">PCFIX_WARRANTY_DAYS</small></dd>
                <dt>"Expiring soon"</dt><dd>{{ config('pcfix.defaults.warranty_expiring_days') }} days before expiry</dd>
                <dt>Auto-convert quotes</dt><dd>{{ ($settings->auto_convert_quote ?? false) ? 'On — Invoice Ninja raises the invoice at approval; the repair job links to it.' : 'Off (recommended) — invoice is generated after verified completion.' }}</dd>
                <dt>IN inventory tracking</dt><dd>{{ $trackInventory ? 'On' : 'Off' }} — parts are deducted by the repair module when used; repair invoices do not deduct again.</dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Notifications</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Email</dt><dd><span class="badge b-good">Active</span> via Invoice Ninja mail settings</dd>
                <dt>In-app</dt><dd><span class="badge b-good">Active</span> bell icon, de-duplicated</dd>
                <dt>SMS</dt><dd>{!! $smsConfigured ? '<span class="badge b-good">Configured</span>' : '<span class="badge">Not configured</span> set PCFIX_SMS_FROM, TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN' !!}</dd>
                <dt>Messenger</dt><dd><span class="badge">Not available</span> no supported integration</dd>
                <dt>Daily automation</dt><dd><span class="mono">php artisan pcfix:daily</span> (scheduled 07:00 via Laravel scheduler)</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
