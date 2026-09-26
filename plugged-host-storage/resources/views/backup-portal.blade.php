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

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-body: #08090c;
            --bg-card: #0f1117;
            --bg-card-hover: #141722;
            --bg-input: #12141c;
            --border-subtle: #1e2230;
            --border-focus: #3b82f6;
            --accent-blue: #3b82f6;
            --accent-blue-hover: #2563eb;
            --accent-blue-glow: rgba(59, 130, 246, 0.25);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --discord: #5865F2;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Subtle background ambient glow */
        .ambient-glow {
            position: fixed;
            top: -220px;
            left: 50%;
            transform: translateX(-50%);
            width: 850px;
            height: 450px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.14) 0%, rgba(8, 9, 12, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }

        header {
            position: relative;
            z-index: 20;
            border-bottom: 1px solid var(--border-subtle);
            background: rgba(15, 17, 23, 0.75);
            backdrop-filter: blur(14px);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
        }

        .brand-logo {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            object-fit: contain;
        }

        .brand-text {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: -0.02em;
        }

        .brand-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.65rem;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--accent-blue);
            border-radius: 9999px;
            font-weight: 600;
            letter-spacing: 0.02em;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        /* Panel Navigation Tabs */
        .panel-nav-tabs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(18, 20, 28, 0.6);
            border: 1px solid var(--border-subtle);
            padding: 0.25rem;
            border-radius: 10px;
        }

        @media (max-width: 820px) {
            .panel-nav-tabs {
                display: none;
            }
        }

        .nav-tab {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.4rem 0.85rem;
            border-radius: 7px;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .nav-tab:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.04);
        }

        .nav-tab.active {
            color: #ffffff;
            background: var(--accent-blue);
            box-shadow: 0 2px 8px var(--accent-blue-glow);
        }

        .auth-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.55rem 1.15rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .btn-primary {
            background-color: var(--accent-blue);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--accent-blue-hover);
            box-shadow: 0 4px 14px var(--accent-blue-glow);
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-subtle);
            color: var(--text-main);
        }
        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.09);
            border-color: var(--text-dim);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
        }
        .btn-outline:hover {
            border-color: var(--text-dim);
            color: var(--text-main);
            background: var(--bg-card);
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.3rem 0.75rem 0.3rem 0.35rem;
            background: var(--bg-input);
            border: 1px solid var(--border-subtle);
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .user-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--border-subtle);
            object-fit: cover;
        }

        main {
            flex: 1;
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 2.75rem 1.5rem;
        }

        /* Hero Banner */
        .hero {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 2.5rem auto;
        }

        .hero h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            margin-bottom: 0.75rem;
            color: #ffffff;
        }

        .hero p {
            color: var(--text-muted);
            font-size: 1.05rem;
            line-height: 1.6;
        }

        /* Search Card */
        .search-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 1.75rem 2rem;
            max-width: 680px;
            margin: 0 auto 3rem auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            position: relative;
        }

        .search-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-card-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1.25rem;
        }

        .search-form {
            display: flex;
            gap: 0.75rem;
        }

        .input-group {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            background: var(--bg-input);
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 0.95rem;
            font-family: 'JetBrains Mono', monospace;
            outline: none;
            transition: all 0.15s ease-in-out;
        }

        .search-input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .search-icon {
            position: absolute;
            left: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            pointer-events: none;
        }

        /* Result Container */
        .result-box {
            margin-top: 1.5rem;
            padding: 1.25rem;
            background: var(--bg-input);
            border: 1px solid var(--border-subtle);
            border-radius: 10px;
            display: none;
        }

        .result-box.active {
            display: block;
            animation: fadeIn 0.2s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .server-title {
            font-size: 1.15rem;
            font-weight: 700;
        }

        .server-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .badge-egg {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
        }

        .badge-node {
            background: rgba(148, 163, 184, 0.1);
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
        }

        .badge-id {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
            font-family: 'JetBrains Mono', monospace;
        }

        .result-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1.25rem;
            line-height: 1.6;
        }

        /* Server Grid */
        .servers-section {
            margin-top: 3.5rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
        }

        .servers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.25rem;
        }

        .server-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease-in-out;
            position: relative;
        }

        .server-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .server-card-focused {
            border-color: var(--accent-blue) !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.35), 0 10px 30px rgba(59, 130, 246, 0.2) !important;
        }

        .server-card-name {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.45rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #ffffff;
        }

        .server-card-info {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-bottom: 1.25rem;
        }

        .card-actions {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-top: auto;
            flex-wrap: wrap;
        }

        .footer {
            border-top: 1px solid var(--border-subtle);
            padding: 2rem 1.5rem;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-dim);
            margin-top: auto;
        }

        .footer a {
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.15s;
        }

        .footer a:hover {
            color: var(--accent-blue);
        }

        .alert-error {
            padding: 0.875rem 1rem;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            color: #fca5a5;
            font-size: 0.875rem;
            margin-top: 1rem;
            display: none;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #141722;
            border: 1px solid var(--border-subtle);
            color: #ffffff;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            font-size: 0.875rem;
            display: none;
            align-items: center;
            gap: 0.5rem;
            z-index: 100;
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>

    <header>
        <div class="nav-container">
            <div class="brand-section">
                <a href="{{ $panelUrl }}" class="brand" title="plugged.host Panel">
                    <img src="https://panel.plugged.host/plugged.host_nobg.png" alt="plugged.host" class="brand-logo">
                    <span class="brand-text">plugged.host</span>
                </a>
                <span class="brand-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Storage Backups
                </span>
            </div>

            <!-- Panel Navigation Tabs -->
            <nav class="panel-nav-tabs">
                <a href="{{ $panelUrl }}/" class="nav-tab">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Servers
                </a>
                <a href="#" class="nav-tab active">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                    Backups Portal
                </a>
                <a href="{{ $panelUrl }}/store" class="nav-tab">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>
                    Store
                </a>
                <a href="https://status.plugged.host" target="_blank" class="nav-tab">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Status
                </a>
            </nav>

            <div class="auth-actions">
                @if ($user)
                    <div class="user-chip">
                        @if ($discordLink && $discordLink->avatar)
                            <img src="{{ $discordLink->avatar }}" alt="{{ $user->username }}" class="user-avatar">
                        @else
                            <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;background:#1e293b;color:#94a3b8;font-size:0.75rem;">
                                {{ strtoupper(substr($user->username, 0, 1)) }}
                            </div>
                        @endif
                        <span>{{ $user->username }}</span>
                    </div>

                    <a href="{{ $panelUrl }}/" class="btn btn-secondary" title="Return to Panel Dashboard">
                        <span>Panel</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>

                    <a href="{{ route('backups.portal.logout') }}" class="btn btn-outline" style="padding:0.55rem 0.75rem;" title="Log Out">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                    </a>
                @else
                    <a href="{{ route('backups.portal.discord') }}" class="btn btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        Log In with Panel
                    </a>
                @endif
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <h1>Game Server Backups</h1>
            <p>Download full archives of your server files. Backups are mirrored directly to our dedicated storage cluster and stripped of build caches, logs, and unneeded dependencies.</p>
        </section>

        @unless ($user)
            <!-- Backups are only listed for the signed-in owner; there is no lookup by server ID. -->
            <div class="search-card">
                <h2 class="search-card-title">Sign in to download your backups</h2>
                <p class="search-card-desc">Your servers and their latest off-site snapshots appear here once you sign in with the account that owns them.</p>
                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <a href="{{ $panelUrl }}/auth/backups-sso" class="btn btn-primary">Sign in with plugged.host</a>
                    <a href="{{ route('backups.portal.discord') }}" class="btn btn-secondary">Sign in with Discord</a>
                </div>
            </div>
        @endunless

        <!-- Authenticated User Server Grid -->
        @if ($user)
            <section class="servers-section">
                <div class="section-header">
                    <div>
                        <h2 class="section-title">Your Game Servers</h2>
                        <p style="color:var(--text-muted);font-size:0.9rem;">View live storage mirror status and download archives directly from our storage cluster.</p>
                    </div>
                </div>

                @if ($userServers->isNotEmpty())
                    <div class="servers-grid">
                        @foreach ($userServers as $srv)
                            <div class="server-card" id="server-card-{{ $srv['uuid_short'] }}">
                                <div class="server-card-top">
                                    <div class="server-card-name" title="{{ $srv['name'] }}">{{ $srv['name'] }}</div>
                                    <div class="server-badges" style="margin-bottom: 0.75rem;">
                                        <span class="badge badge-egg">{{ $srv['egg'] }}</span>
                                        <span class="badge badge-id" onclick="copyId('{{ $srv['uuid_short'] }}')" style="cursor:pointer;" title="Click to copy ID">
                                            #{{ $srv['uuid_short'] }}
                                        </span>
                                        <span class="badge badge-node">{{ $srv['node'] }}</span>
                                    </div>
                                    <div class="server-card-info">
                                        @if ($srv['has_backup'])
                                            <span style="color:#10b981;font-weight:600;display:inline-flex;align-items:center;gap:0.35rem;">
                                                <span style="width:7px;height:7px;border-radius:50%;background:#10b981;"></span>
                                                Backup Ready
                                            </span>
                                            @if ($srv['bytes'])
                                                &bull; {{ number_format($srv['bytes'] / (1024 * 1024), 1) }} MB
                                            @endif
                                            @if (!empty($srv['mtime']))
                                                &bull; {{ \Carbon\Carbon::parse($srv['mtime'])->diffForHumans() }}
                                            @endif
                                        @else
                                            <span style="color:var(--text-dim);display:inline-flex;align-items:center;gap:0.35rem;">
                                                <span style="width:7px;height:7px;border-radius:50%;background:var(--text-dim);"></span>
                                                Sync scheduled from node
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="card-actions">
                                    @if ($srv['has_backup'])
                                        <a href="{{ $srv['download_url'] }}" class="btn btn-primary" style="font-size:0.825rem;padding:0.5rem 0.9rem;">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                <polyline points="7 10 12 15 17 10"></polyline>
                                                <line x1="12" y1="15" x2="12" y2="3"></line>
                                            </svg>
                                            Download (.tar.gz)
                                        </a>
                                    @else
                                        <button class="btn btn-outline" disabled style="font-size:0.825rem;padding:0.5rem 0.9rem;opacity:0.6;cursor:not-allowed;" title="Sync pending from node">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            Sync Pending
                                        </button>
                                    @endif

                                    <a href="{{ $srv['manage_url'] }}" class="btn btn-secondary" style="font-size:0.825rem;padding:0.5rem 0.75rem;" title="Manage Server in Panel">
                                        <span>Manage</span>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                        </svg>
                                    </a>

                                    <a href="{{ $srv['backups_url'] }}" class="btn btn-outline" style="font-size:0.825rem;padding:0.5rem 0.75rem;" title="View in Server Backups Tab">
                                        Backups
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div style="background:var(--bg-card);border:1px solid var(--border-subtle);border-radius:12px;padding:2.5rem;text-align:center;">
                        <p style="color:var(--text-muted);margin-bottom:0.75rem;">No active servers found on this account.</p>
                        <p style="font-size:0.85rem;color:var(--text-dim);">If you have a server ID from a previous snapshot or another node, use the search box above to download it!</p>
                    </div>
                @endif
            </section>
        @endif
    </main>

    <footer class="footer">
        <p>&copy; {{ date('Y') }} plugged.host &bull; Game Server Hosting &bull; <a href="{{ $panelUrl }}" target="_blank">Panel Dashboard</a> &bull; <a href="https://status.plugged.host" target="_blank">System Status</a> &bull; <a href="https://discord.gg/TYUrdQbYFt" target="_blank">Discord Support</a></p>
    </footer>

    <div id="toast" class="toast">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#10b981" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
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
