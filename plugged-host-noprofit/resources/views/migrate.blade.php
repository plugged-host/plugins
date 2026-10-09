<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/plugged.host_nobg.png" />
    <title>Authorize Account &bull; plugged.host</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @include('plugged-host-core::partials.standalone-auth-styles')
</head>
<body>

    <div class="auth-card">
        <div class="auth-header">
            <img src="/plugged.host_nobg.png" alt="plugged.host" class="auth-logo">
            <h1 class="auth-title">Connect plugged.host Account</h1>
            <p class="auth-subtitle">Sign in with your plugged.host credentials to link your services</p>
        </div>

        @if($errors->any())
            <div class="alert-danger">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('plugged-host.migrate.submit') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $state }}">

            <div class="field-group">
                <label for="identifier" class="field-label">
                    Username or Email<sup class="required-mark">*</sup>
                </label>
                <div class="fi-input-wrp">
                    <input id="identifier" type="text" name="identifier" value="{{ old('identifier') }}" required autofocus
                           class="fi-input" placeholder="Your username or email" tabindex="1">
                </div>
            </div>

            <div class="field-group field-group-last">
                <label for="password" class="field-label">
                    Password<sup class="required-mark">*</sup>
                </label>
                <div class="fi-input-wrp">
                    <input id="password" type="password" name="password" required
                           class="fi-input" placeholder="••••••••" tabindex="2">
                </div>
            </div>

            <div class="auth-actions">
                <button type="submit" class="btn-submit">Continue to noprofit.space</button>

                <a href="{{ $cancelUrl }}" class="btn-cancel">Cancel and return</a>

                <p class="auth-note">
                    Already moved your account to noprofit.space? Your plugged.host password was moved with it &mdash;
                    <a href="{{ $accountsUrl }}/login">sign in there instead</a>.
                </p>
            </div>
        </form>
    </div>

</body>
</html>
