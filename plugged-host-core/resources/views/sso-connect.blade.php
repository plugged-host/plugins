<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/plugged.host_nobg.png" />
    <title>Connect your account &bull; plugged.host</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @include('plugged-host-core::partials.standalone-auth-styles')
</head>
<body>
    <div class="auth-card">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: center; margin-bottom: 0.75rem;">
                <img src="/plugged.host_nobg.png" alt="plugged.host" style="height: 2.75rem; width: auto; object-fit: contain;">
            </div>
            <h1 style="font-size: 1.375rem; font-weight: 700; color: #ffffff; margin: 0; letter-spacing: -0.02em;">
                Connect your plugged.host account
            </h1>
            <p style="font-size: 0.8125rem; color: #9ca3af; margin: 0.625rem 0 0 0; line-height: 1.5;">
                A plugged.host account already uses this email address. To keep it safe, confirm it's yours
                once with your plugged.host password. After that, signing in with noprofit.space just works.
            </p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <a href="{{ $connectUrl }}" class="btn-submit">
                <span>Connect with my plugged.host password</span>
            </a>
            <div style="text-align: center; margin-top: 0.5rem;">
                <a href="{{ $accountsUrl }}/dashboard" class="btn-cancel">&larr; Back to noprofit.space</a>
            </div>
        </div>
    </div>
</body>
</html>
