<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · PCFix Hub Operations</title>
    <link rel="stylesheet" href="{{ asset('css/pcfix-ops.css') }}?v=1">
</head>
<body>
<div class="center-page">
    <div class="auth-card">
        <div class="brand" style="color:var(--text);justify-content:center;margin-bottom:12px">
            <span class="brand-mark" aria-hidden="true">PC</span>
            <span>PCFix Hub<small style="color:var(--muted)">Operations Console</small></span>
        </div>
        <div class="card">
            <div class="card-body">
                <h1 style="margin-bottom:4px">Sign in</h1>
                <p class="muted" style="margin-top:0">Use your Invoice Ninja account.</p>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('ops.login.submit') }}">
                    @csrf
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password">
                    </div>
                    @if (session('ops_need_otp') || $errors->has('one_time_password'))
                        <div class="field">
                            <label for="otp">Two-factor code</label>
                            <input id="otp" type="text" name="one_time_password" inputmode="numeric" autocomplete="one-time-code" maxlength="10" autofocus>
                        </div>
                    @endif
                    <button class="btn btn-primary" style="width:100%">Sign in</button>
                </form>
            </div>
        </div>
        <p class="muted" style="text-align:center;font-size:12px;margin-top:12px">
            <a href="{{ url('/') }}">Open the Invoice Ninja admin app</a>
        </p>
    </div>
</div>
</body>
</html>
