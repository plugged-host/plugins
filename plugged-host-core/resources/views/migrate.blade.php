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
    <style>
        *, ::before, ::after {
            box-sizing: border-box;
            border-width: 0;
            border-style: solid;
            border-color: rgba(255, 255, 255, 0.1);
        }
        body {
            background-color: #0b0d10;
            background-image: radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.03) 0%, transparent 60%), linear-gradient(to bottom, #101319 0%, #0b0d10 100%);
            color: #ffffff;
            font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }
        .auth-card {
            background-color: #12141a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.875rem;
            width: 100%;
            max-width: 27.5rem;
            padding: 2.25rem 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .fi-input-wrp {
            display: flex;
            align-items: center;
            width: 100%;
            background-color: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .fi-input-wrp:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }
        .fi-input {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: #ffffff;
            font-size: 0.875rem;
            line-height: 1.25rem;
            padding: 0.65rem 0.875rem;
            font-family: inherit;
        }
        .fi-input::placeholder {
            color: #52525b;
        }
        .btn-submit {
            width: 100%;
            background-color: #1d4ed8;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.65rem 1rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            transition: background-color 0.15s ease;
            text-decoration: none;
            font-family: inherit;
            box-shadow: none;
        }
        .btn-submit:hover {
            background-color: #2563eb;
        }
        .btn-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8125rem;
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .btn-cancel:hover {
            color: #ffffff;
        }
        .alert-danger {
            background-color: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.25);
            color: #fda4af;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }
        .field-group {
            margin-bottom: 1.125rem;
        }
        .field-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #ffffff;
            margin-bottom: 0.375rem;
        }
        .required-mark {
            color: #f43f5e;
            font-weight: 700;
            margin-left: 0.125rem;
        }
    </style>
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
            <input type="hidden" name="return_to" value="{{ $returnTo }}">

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
                    <a href="{{ $returnTo }}" class="btn-cancel">
                        &larr; Cancel and return
                    </a>
                </div>
            </div>
        </form>
    </div>

</body>
</html>
