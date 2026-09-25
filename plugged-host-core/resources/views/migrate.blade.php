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
        <!-- Logo & Header -->
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: center; margin-bottom: 0.75rem;">
                <img src="/plugged.host_nobg.png" alt="plugged.host" style="height: 2.75rem; width: auto; object-fit: contain;">
            </div>
            <h1 style="font-size: 1.375rem; font-weight: 700; color: #ffffff; margin: 0; letter-spacing: -0.02em;">
                Connect plugged.host Account
            </h1>
            <p style="font-size: 0.8125rem; color: #9ca3af; margin: 0.375rem 0 0 0;">
                Sign in with your plugged.host credentials to link your services
            </p>
        </div>

        @if($errors->any())
            <div class="alert-danger">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('plugged-host.migrate.submit') }}">
            @csrf

            <div class="field-group">
                <label for="identifier" class="field-label">
                    Username or Email<sup class="required-mark">*</sup>
                </label>
                <div class="fi-input-wrp">
                    <input id="identifier" type="text" name="identifier" value="{{ old('identifier') }}" required autofocus
                           class="fi-input" placeholder="Your username or email" tabindex="1">
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 1.5rem;">
                <label for="password" class="field-label">
                    Password<sup class="required-mark">*</sup>
                </label>
                <div class="fi-input-wrp">
                    <input id="password" type="password" name="password" required
                           class="fi-input" placeholder="••••••••" tabindex="2">
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <button type="submit" class="btn-submit">
                    <span>Continue to noprofit.space</span>
                    <svg style="width: 1rem; height: 1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>

                <div style="text-align: center; margin-top: 0.5rem;">
                    <a href="{{ $cancelUrl }}" class="btn-cancel">
                        &larr; Cancel and return
                    </a>
                </div>
            </div>
        </form>
    </div>

</body>
</html>
