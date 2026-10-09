<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ config('app.favicon', '/plugged.host_nobg.png') }}" />
    <title>Connect your account &bull; plugged.host</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @include('plugged-host-core::partials.standalone-auth-styles')
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <img src="{{ config('app.logo', '/plugged.host_nobg.png') }}" alt="plugged.host" class="auth-logo">
            <h1 class="auth-title">Connect your plugged.host account</h1>
            <p class="auth-subtitle">
                A plugged.host account already uses this email address. To keep it safe, confirm it's yours
                once with your plugged.host password. After that, signing in with noprofit.space just works.
            </p>
        </div>

        <div class="auth-actions">
            <a href="{{ $connectUrl }}" class="btn-submit">Connect with my plugged.host password</a>
            <a href="{{ $accountsUrl }}/dashboard" class="btn-cancel">Back to noprofit.space</a>
        </div>
    </div>
</body>
</html>
