    {{--
        Styles for the standalone auth pages (migrate, sso-connect).

        These pages do not load the panel theme, so the few theme v2 tokens they use are
        declared here under the same names and with the same values as
        plugged-host-theme-v2/resources/css/theme.css. Flat: the theme's page floor, one card
        surface, a pill primary button, a text link for going back. No shadows, no glows.
    --}}
    <style>
        :root {
            --phv2-accent: #1d4ed8;
            --phv2-accent-hover: #1e40af;
            --phv2-accent-bright: #3b82f6;
            --phv2-bg-top: #101319;
            --phv2-bg-bottom: #0b0d10;
            --phv2-sunken: rgb(15 17 22 / 0.5);
            --phv2-raise: rgb(255 255 255 / 0.05);
            --phv2-hairline: rgb(255 255 255 / 0.05);
            --phv2-text: #ffffff;
            --phv2-text-nav: #d1d5db;
            --phv2-text-muted: #9ca3af;
            --phv2-text-faint: #71717a;
            --ph-danger: #ef4444;
            --ph-danger-text: #f87171;
            color-scheme: dark;
        }
        *, ::before, ::after {
            box-sizing: border-box;
            border-width: 0;
            border-style: solid;
            border-color: var(--phv2-hairline);
        }
        body {
            background-color: var(--phv2-bg-bottom);
            background-image: linear-gradient(to bottom, var(--phv2-bg-top), var(--phv2-bg-bottom));
            background-attachment: fixed;
            color: var(--phv2-text);
            font-family: 'Space Grotesk', ui-sans-serif, system-ui, sans-serif;
            font-size: 0.875rem;
            line-height: 1.25rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }
        .auth-card {
            background-color: var(--phv2-raise);
            border-radius: 1rem;
            width: 100%;
            max-width: 27.5rem;
            padding: 1.5rem;
        }
        @media (min-width: 40rem) {
            .auth-card {
                padding: 2rem;
            }
        }
        .auth-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .auth-logo {
            height: 2.5rem;
            width: auto;
            object-fit: contain;
            margin-bottom: 0.5rem;
        }
        .auth-title {
            font-size: 1.25rem;
            line-height: 1.75rem;
            font-weight: 600;
            color: var(--phv2-text);
            margin: 0;
        }
        .auth-subtitle {
            font-size: 0.875rem;
            line-height: 1.375rem;
            color: var(--phv2-text-muted);
            margin: 0;
        }
        .auth-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
        .auth-note {
            margin: 0;
            text-align: center;
            font-size: 0.75rem;
            line-height: 1.125rem;
            color: var(--phv2-text-faint);
        }
        .auth-note a {
            color: var(--phv2-text-muted);
        }
        .auth-note a:hover {
            color: var(--phv2-text);
        }
        .fi-input-wrp {
            display: flex;
            align-items: center;
            width: 100%;
            background-color: var(--phv2-sunken);
            border: 1px solid var(--phv2-hairline);
            border-radius: 0.5rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .fi-input-wrp:focus-within {
            border-color: var(--phv2-accent-bright);
            box-shadow: 0 0 0 1px var(--phv2-accent-bright);
        }
        .fi-input {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: var(--phv2-text);
            font-size: 0.875rem;
            line-height: 1.25rem;
            padding: 0.625rem 0.75rem;
            font-family: inherit;
        }
        .fi-input::placeholder {
            color: var(--phv2-text-faint);
        }
        .btn-submit {
            width: 100%;
            min-height: 2.75rem;
            padding: 0.5rem 1.25rem;
            background-color: var(--phv2-accent);
            color: #ffffff;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.875rem;
            line-height: 1.25rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s ease, transform 0.1s ease;
        }
        .btn-submit:hover {
            background-color: var(--phv2-accent-hover);
        }
        .btn-submit:active {
            transform: scale(0.97);
        }
        .btn-submit:focus-visible,
        .btn-cancel:focus-visible {
            outline: 2px solid var(--phv2-accent-bright);
            outline-offset: 2px;
        }
        .btn-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8125rem;
            color: var(--phv2-text-muted);
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .btn-cancel:hover {
            color: var(--phv2-text);
        }
        .alert-danger {
            background-color: color-mix(in oklab, var(--ph-danger) 12%, transparent);
            color: var(--ph-danger-text);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            margin-bottom: 1.25rem;
            line-height: 1.25rem;
        }
        .field-group {
            margin-bottom: 1rem;
        }
        .field-group-last {
            margin-bottom: 1.5rem;
        }
        .field-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--phv2-text);
            margin-bottom: 0.5rem;
        }
        .required-mark {
            color: var(--ph-danger-text);
            font-weight: 600;
            margin-left: 0.125rem;
        }
    </style>
    {{-- The accent from the Theme v2 settings, over the blue tokens above. --}}
    @includeWhen(\PluggedHost\Core\Support\Plugins::enabled('plugged-host-theme-v2'), 'plugged-host-theme-v2::accent')
