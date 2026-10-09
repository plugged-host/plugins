<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ config('app.favicon', '/plugged.host_nobg.png') }}" />
    <title>Two-factor check &bull; plugged.host</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @include('plugged-host-core::partials.standalone-auth-styles')
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <img src="{{ config('app.logo', '/plugged.host_nobg.png') }}" alt="plugged.host" class="auth-logo">
            <h1 class="auth-title">Two-factor check</h1>
            <p class="auth-subtitle">
                @if($method === 'email')
                    We emailed you a 6-digit code.
                @else
                    Enter the 6-digit code from your authenticator app, or one of your recovery codes.
                @endif
            </p>
        </div>

        @if($errors->any())
            <div class="alert-danger">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('plugged-host.sso.challenge.verify') }}">
            @csrf
            <div class="field-group field-group-last">
                <label for="code" class="field-label">Code<sup class="required-mark">*</sup></label>
                <div class="fi-input-wrp">
                    <input id="code" type="text" name="code" required autofocus autocomplete="one-time-code"
                           inputmode="text" class="fi-input" placeholder="123456">
                </div>
            </div>
            <button type="submit" class="btn-submit">Continue</button>
        </form>
    </div>
</body>
</html>
