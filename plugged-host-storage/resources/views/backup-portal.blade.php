<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server Backups &bull; plugged.host</title>
    <link rel="icon" type="image/png" href="https://panel.plugged.host/plugged.host_nobg.png">
    <meta name="description" content="Download dedicated off-site backups for your plugged.host game servers.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{--
        Standalone page: the panel theme is not loaded here, so the theme v2 tokens it uses are
        declared below under the same names and with the same values as
        plugged-host-theme-v2/resources/css/theme.css. Flat on purpose: the theme's page floor,
        one card surface, pill buttons, full-width server rows. No glows, blur or shadows
        beyond the toast's popover elevation.
    --}}
    <style>
        :root {
            --phv2-accent: #1d4ed8;
            --phv2-accent-hover: #1e40af;
            --phv2-accent-bright: #3b82f6;
            --phv2-accent-soft: rgb(29 78 216 / 0.1);
            --phv2-bg-top: #101319;
            --phv2-bg-bottom: #0b0d10;
            --phv2-modal: #1a1c20;
            --phv2-raise: rgb(255 255 255 / 0.05);
            --phv2-raise-2: rgb(255 255 255 / 0.1);
            --phv2-raise-3: rgb(255 255 255 / 0.15);
            --phv2-hairline: rgb(255 255 255 / 0.05);
            --phv2-text: #ffffff;
            --phv2-text-nav: #d1d5db;
            --phv2-text-muted: #9ca3af;
            --phv2-text-faint: #71717a;
            --ph-success: #22c55e;
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: inherit;
        }

        :focus-visible {
            outline: 2px solid var(--phv2-accent-bright);
            outline-offset: 2px;
        }

        .container {
            width: 100%;
            max-width: 64rem;
            margin: 0 auto;
            padding-inline: 1rem;
        }

        @media (min-width: 40rem) {
            .container {
                padding-inline: 1.5rem;
            }
        }

        /* ---- Top bar ---- */
        .topbar {
            border-bottom: 1px solid var(--phv2-hairline);
        }

        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding-block: 0.75rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--phv2-text);
        }

        .brand-logo {
            width: 2rem;
            height: 2rem;
            object-fit: contain;
        }

        .brand-text {
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 600;
        }

        .topnav {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        @media (max-width: 820px) {
            .topnav {
                display: none;
            }
        }

        .topnav a {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--phv2-text-nav);
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            transition: background-color 150ms ease, color 150ms ease;
        }

        .topnav a:hover,
        .topnav a[aria-current="page"] {
            background-color: var(--phv2-raise-2);
            color: var(--phv2-text);
        }

        .account {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
        }

        .user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
            font-weight: 500;
            color: var(--phv2-text-nav);
        }

        .user-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 10rem;
        }

        .user-avatar {
            width: 1.75rem;
            height: 1.75rem;
            flex: none;
            border-radius: 9999px;
            object-fit: cover;
        }

        .user-avatar-fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--phv2-raise-2);
            color: var(--phv2-text-muted);
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* ---- Buttons: the panel's pill primary / gray secondary, text links, icon buttons ---- */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            padding: 0.5rem 1.25rem;
            border: none;
            border-radius: 9999px;
            font-family: inherit;
            font-size: 0.875rem;
            line-height: 1.25rem;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background-color 150ms ease, transform 100ms ease;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn-sm {
            min-height: 2.125rem;
            padding: 0.375rem 0.875rem;
            font-size: 0.8125rem;
        }

        .btn-primary {
            background-color: var(--phv2-accent);
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: var(--phv2-accent-hover);
        }

        .btn-secondary {
            background-color: var(--phv2-raise-2);
            color: var(--phv2-text);
        }

        .btn-secondary:hover {
            background-color: var(--phv2-raise-3);
        }

        .link {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--phv2-text-nav);
            text-decoration: none;
            transition: color 150ms ease;
        }

        .link:hover {
            color: var(--phv2-text);
        }

        .icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            flex: none;
            border-radius: 0.5rem;
            color: var(--phv2-text-muted);
            transition: background-color 150ms ease, color 150ms ease;
        }

        .icon-btn:hover {
            background-color: var(--phv2-raise);
            color: var(--phv2-text);
        }

        .icon-btn svg {
            width: 1.25rem;
            height: 1.25rem;
        }

        /* ---- Page ---- */
        main {
            flex: 1;
            padding-block: 2rem 3rem;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.25rem;
            line-height: 1.75rem;
            font-weight: 600;
            color: var(--phv2-text);
        }

        .page-subtitle {
            margin-top: 0.25rem;
            max-width: 40rem;
            font-size: 1rem;
            line-height: 1.5rem;
            color: var(--phv2-text-muted);
        }

        .card {
            background-color: var(--phv2-raise);
            border-radius: 1rem;
            padding: 1.5rem;
        }

        .signin-card {
            max-width: 40rem;
        }

        .card-title,
        .section-title {
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 600;
            color: var(--phv2-text);
        }

        .card-text {
            margin-top: 0.25rem;
            color: var(--phv2-text-muted);
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .section-title {
            margin-bottom: 1rem;
        }

        /* ---- Server rows: one full-width bar per server ---- */
        .server-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .server-row {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid transparent;
            border-radius: 0.75rem;
            background-color: var(--phv2-raise);
            transition: border-color 150ms ease, background-color 150ms ease;
        }

        @media (min-width: 40rem) {
            .server-row {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 1.5rem;
                padding: 1rem 1.5rem;
            }
        }

        /* Deep-linked from the panel (?server=…): the selected-row look. */
        .server-card-focused {
            border-color: var(--phv2-accent-bright);
            background-color: var(--phv2-accent-soft);
        }

        .server-main {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }

        .server-name {
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 600;
            color: var(--phv2-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .server-meta,
        .server-status {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.5rem;
            font-size: 0.8125rem;
            line-height: 1.25rem;
            color: var(--phv2-text-muted);
        }

        .server-status {
            color: var(--phv2-text-nav);
        }

        .server-id {
            border: none;
            background: none;
            padding: 0;
            font: inherit;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            color: var(--phv2-text-muted);
            cursor: pointer;
            transition: color 150ms ease;
        }

        .server-id:hover {
            color: var(--phv2-text);
        }

        .sep {
            color: var(--phv2-text-faint);
        }

        .dot {
            width: 0.5rem;
            height: 0.5rem;
            flex: none;
            border-radius: 9999px;
            background-color: var(--phv2-text-faint);
        }

        .dot-ok {
            background-color: var(--ph-success);
        }

        .server-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }

        .empty {
            color: var(--phv2-text-muted);
        }

        /* ---- Footer ---- */
        .footer {
            border-top: 1px solid var(--phv2-hairline);
            padding-block: 1.5rem;
            text-align: center;
            font-size: 0.8125rem;
            color: var(--phv2-text-faint);
        }

        .footer a {
            color: var(--phv2-text-muted);
            text-decoration: none;
            transition: color 150ms ease;
        }

        .footer a:hover {
            color: var(--phv2-text);
        }

        /* ---- Copy confirmation: a popover, so it keeps the popover elevation ---- */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            left: auto;
            max-width: calc(100% - 3rem);
            background-color: var(--phv2-modal);
            color: var(--phv2-text);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.5), 0 0 0 1px var(--phv2-hairline);
            font-size: 0.875rem;
            display: none;
            align-items: center;
            z-index: 100;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <a href="{{ $panelUrl }}" class="brand" title="plugged.host Panel">
                <img src="https://panel.plugged.host/plugged.host_nobg.png" alt="plugged.host" class="brand-logo">
                <span class="brand-text">plugged.host</span>
            </a>

            <nav class="topnav">
                <a href="{{ $panelUrl }}/">Servers</a>
                <a href="#" aria-current="page">Backups</a>
                <a href="{{ $panelUrl }}/store">Store</a>
                <a href="https://status.plugged.host" target="_blank">Status</a>
            </nav>

            <div class="account">
                @if ($user)
                    <div class="user">
                        @if ($discordLink && $discordLink->avatar)
                            <img src="{{ $discordLink->avatar }}" alt="{{ $user->username }}" class="user-avatar">
                        @else
                            <div class="user-avatar user-avatar-fallback">
                                {{ strtoupper(substr($user->username, 0, 1)) }}
                            </div>
                        @endif
                        <span class="user-name">{{ $user->username }}</span>
                    </div>

                    <a href="{{ $panelUrl }}/" class="btn btn-secondary btn-sm" title="Return to Panel Dashboard">Panel</a>

                    <a href="{{ route('backups.portal.logout') }}" class="icon-btn" title="Log out" aria-label="Log out">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                    </a>
                @else
                    <a href="{{ route('backups.portal.discord') }}" class="btn btn-secondary btn-sm">Log In with Panel</a>
                @endif
            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-header">
            <h1 class="page-title">Server backups</h1>
            <p class="page-subtitle">Download a full copy of your server files. Copies are kept on a separate storage server, without caches, logs and other files your server can rebuild.</p>
        </div>

        @unless ($user)
            {{-- Backups are only listed for the signed-in owner; there is no lookup by server ID. --}}
            <section class="card signin-card">
                <h2 class="card-title">Sign in to download your backups</h2>
                <p class="card-text">Your servers and their latest off-site copies appear here once you sign in with the account that owns them.</p>
                <div class="card-actions">
                    <a href="{{ $panelUrl }}/auth/backups-sso" class="btn btn-primary">Sign in with plugged.host</a>
                    <a href="{{ route('backups.portal.discord') }}" class="btn btn-secondary">Sign in with Discord</a>
                </div>
            </section>
        @endunless

        @if ($user)
            <section>
                <h2 class="section-title">Your servers</h2>

                @if ($userServers->isNotEmpty())
                    <div class="server-list">
                        @foreach ($userServers as $srv)
                            <div class="server-row" id="server-card-{{ $srv['uuid_short'] }}">
                                <div class="server-main">
                                    <div class="server-name" title="{{ $srv['name'] }}">{{ $srv['name'] }}</div>

                                    <div class="server-meta">
                                        <span>{{ $srv['egg'] }}</span>
                                        <span class="sep" aria-hidden="true">&middot;</span>
                                        <span>{{ $srv['node'] }}</span>
                                        <span class="sep" aria-hidden="true">&middot;</span>
                                        <button type="button" class="server-id" onclick="copyId('{{ $srv['uuid_short'] }}')" title="Copy server ID">#{{ $srv['uuid_short'] }}</button>
                                    </div>

                                    <div class="server-status">
                                        @if ($srv['has_backup'])
                                            <span class="dot dot-ok" aria-hidden="true"></span>
                                            <span>Backup ready</span>
                                            @if ($srv['bytes'])
                                                <span class="sep" aria-hidden="true">&middot;</span>
                                                <span>{{ number_format($srv['bytes'] / (1024 * 1024), 1) }} MB</span>
                                            @endif
                                            @if (!empty($srv['mtime']))
                                                <span class="sep" aria-hidden="true">&middot;</span>
                                                <span>{{ \Carbon\Carbon::parse($srv['mtime'])->diffForHumans() }}</span>
                                            @endif
                                        @else
                                            <span class="dot" aria-hidden="true"></span>
                                            <span>Waiting for the first copy from the node</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="server-actions">
                                    @if ($srv['has_backup'])
                                        <a href="{{ $srv['download_url'] }}" class="btn btn-secondary btn-sm">Download</a>
                                    @endif

                                    <a href="{{ $srv['manage_url'] }}" class="link" title="Manage Server in Panel">Manage</a>

                                    <a href="{{ $srv['backups_url'] }}" class="link" title="View in Server Backups Tab">Backups</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="card empty">No active servers found on this account.</div>
                @endif
            </section>
        @endif
    </main>

    <footer class="footer">
        <div class="container">
            &copy; {{ date('Y') }} plugged.host &middot; <a href="{{ $panelUrl }}" target="_blank">Panel</a> &middot; <a href="https://status.plugged.host" target="_blank">Status</a> &middot; <a href="https://discord.gg/TYUrdQbYFt" target="_blank">Discord support</a>
        </div>
    </footer>

    <div id="toast" class="toast" role="status" aria-live="polite">
        <span id="toastMsg">Copied to clipboard!</span>
    </div>

    <script>
        function copyId(id) {
            navigator.clipboard.writeText(id).then(() => {
                const toast = document.getElementById('toast');
                document.getElementById('toastMsg').textContent = 'Server ID #' + id + ' copied!';
                toast.style.display = 'flex';
                setTimeout(() => { toast.style.display = 'none'; }, 2000);
            });
        }

        // Deep-linking handler
        document.addEventListener('DOMContentLoaded', () => {
            const initialServer = @js($initialServerId);
            if (initialServer) {
                const targetCard = document.getElementById('server-card-' + initialServer);
                if (targetCard) {
                    targetCard.classList.add('server-card-focused');
                    targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    </script>
</body>
</html>
