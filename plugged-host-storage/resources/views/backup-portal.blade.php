<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server Backups &mdash; plugged.host</title>
    <link rel="icon" type="image/png" href="https://panel.plugged.host/plugged.host_nobg.png">
    <meta name="description" content="Download backups for your plugged.host game servers.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-body: #08090c;
            --bg-card: #0f1117;
            --bg-card-hover: #141720;
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
            --danger: #ef4444;
            --discord: #5865F2;
            --discord-hover: #4752c4;
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

        /* Subtle background glow effect */
        .ambient-glow {
            position: fixed;
            top: -200px;
            left: 50%;
            transform: translateX(-50%);
            width: 800px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.12) 0%, rgba(8, 9, 12, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }

        header {
            position: relative;
            z-index: 10;
            border-bottom: 1px solid var(--border-subtle);
            background: rgba(15, 17, 23, 0.7);
            backdrop-filter: blur(12px);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
        }

        .brand-logo {
            width: 32px;
            height: 32px;
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
            padding: 0.2rem 0.6rem;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--accent-blue);
            border-radius: 9999px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .auth-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .btn-discord {
            background-color: var(--discord);
            color: #ffffff;
        }
        .btn-discord:hover {
            background-color: var(--discord-hover);
            box-shadow: 0 4px 14px rgba(88, 101, 242, 0.35);
        }

        .btn-primary {
            background-color: var(--accent-blue);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--accent-blue-hover);
            box-shadow: 0 4px 14px var(--accent-blue-glow);
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

        .user-badge {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.875rem 0.375rem 0.375rem;
            background: var(--bg-input);
            border: 1px solid var(--border-subtle);
            border-radius: 9999px;
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--border-subtle);
        }

        .user-name {
            font-size: 0.875rem;
            font-weight: 600;
        }

        main {
            flex: 1;
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 3rem 1.5rem;
        }

        .hero {
            text-align: center;
            max-width: 680px;
            margin: 0 auto 3rem auto;
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
        }

        /* Search Box Card */
        .search-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 2rem;
            max-width: 680px;
            margin: 0 auto 3.5rem auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        .search-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-card-desc {
            font-size: 0.875rem;
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
            padding: 0.875rem 1rem 0.875rem 2.75rem;
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

        /* Lookup Result Container */
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

        /* Server Grid for Authenticated Users */
        .servers-section {
            margin-top: 3.5rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .servers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.25rem;
        }

        .server-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease-in-out;
        }

        .server-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }

        .server-card-top {
            margin-bottom: 1rem;
        }

        .server-card-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .server-card-info {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
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

        @media (max-width: 640px) {
            .search-form {
                flex-direction: column;
            }
            .hero h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>

    <header>
        <div class="nav-container">
            <a href="/" class="brand">
                <img src="https://panel.plugged.host/plugged.host_nobg.png" alt="plugged.host" class="brand-logo">
                <span class="brand-text">plugged.host</span>
                <span class="brand-badge">Backups</span>
            </a>

            <div class="auth-actions">
                @if ($user)
                    <div class="user-badge">
                        @if ($discordLink && $discordLink->discord_avatar)
                            <img src="https://cdn.discordapp.com/avatars/{{ $discordLink->discord_id }}/{{ $discordLink->discord_avatar }}.png" alt="Avatar" class="user-avatar">
                        @else
                            <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.75rem;color:#94a3b8;">
                                {{ strtoupper(substr($user->username, 0, 1)) }}
                            </div>
                        @endif
                        <span class="user-name">{{ $user->username }}</span>
                    </div>
                    <a href="{{ route('backups.portal.logout') }}" class="btn btn-outline" style="padding: 0.4rem 0.85rem; font-size: 0.8rem;">Log out</a>
                @else
                    <a href="{{ route('backups.portal.discord') }}" class="btn btn-discord">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.929 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.893.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                        </svg>
                        Login with Discord
                    </a>
                @endif
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <h1>Game Server Backups</h1>
            <p>Download full archives of your server files. Backups are deduplicated and stripped of build caches, logs, and unneeded dependencies.</p>
        </section>

        <!-- Search Form -->
        <div class="search-card">
            <h2 class="search-card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-blue)">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
                Find Server Backup
            </h2>
            <p class="search-card-desc">Enter your 8-character Server ID (e.g. <code style="color:#93c5fd;font-family:'JetBrains Mono'">5e5de4be</code>) or full server UUID.</p>

            <form id="searchForm" class="search-form" onsubmit="handleSearch(event)">
                <div class="input-group">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="serverIdInput" class="search-input" placeholder="e.g. 5e5de4be" autocomplete="off" spellcheck="false" required>
                </div>
                <button type="submit" id="searchBtn" class="btn btn-primary">
                    <span id="btnText">Look Up</span>
                    <span id="btnSpinner" class="spinner" style="display:none;"></span>
                </button>
            </form>

            <div id="searchError" class="alert-error"></div>

            <!-- Dynamic Result Card -->
            <div id="resultBox" class="result-box">
                <div class="result-header">
                    <div id="resultName" class="server-title">Server Name</div>
                    <span id="resultBackupStatus" class="badge" style="background:rgba(16,185,129,0.15);color:#6ee7b7;border:1px solid rgba(16,185,129,0.3)">
                        ● Backup Available
                    </span>
                </div>

                <div class="server-badges">
                    <span id="resultEgg" class="badge badge-egg">Minecraft: Java Edition</span>
                    <span id="resultNode" class="badge badge-node">Node: storage</span>
                    <span id="resultShortId" class="badge badge-id">ID: 5e5de4be</span>
                </div>

                <div class="result-meta">
                    <div id="resultMetaDetails">Size: ~150 MB &bull; Filtered cache & node_modules excluded</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.25rem;">
                        UUID: <span id="resultFullUuid" style="font-family:'JetBrains Mono'"></span>
                    </div>
                </div>

                <a id="resultDownloadBtn" href="#" class="btn btn-primary" style="width:100%;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Download Backup Archive (.tar.gz)
                </a>
            </div>
        </div>

        <!-- Authenticated User Server Grid -->
        @if ($user)
            <section class="servers-section">
                <div class="section-header">
                    <div>
                        <h2 class="section-title">Your Servers</h2>
                        <p style="color:var(--text-muted);font-size:0.9rem;">Quickly download backups for all servers linked to your account.</p>
                    </div>
                </div>

                @if ($userServers->isNotEmpty())
                    <div class="servers-grid">
                        @foreach ($userServers as $srv)
                            <div class="server-card">
                                <div class="server-card-top">
                                    <div class="server-card-name" title="{{ $srv['name'] }}">{{ $srv['name'] }}</div>
                                    <div class="server-badges" style="margin-bottom: 0.75rem;">
                                        <span class="badge badge-egg">{{ $srv['egg'] }}</span>
                                        <span class="badge badge-id">{{ $srv['uuid_short'] }}</span>
                                        <span class="badge badge-node">{{ $srv['node'] }}</span>
                                    </div>
                                    <div class="server-card-info">
                                        @if ($srv['has_backup'])
                                            <span style="color:#10b981;font-weight:600;">● Backup Ready</span>
                                            @if ($srv['bytes'])
                                                &bull; {{ number_format($srv['bytes'] / (1024 * 1024), 1) }} MB
                                            @endif
                                        @else
                                            <span style="color:var(--text-dim);">○ Backup syncing soon</span>
                                        @endif
                                    </div>
                                </div>

                                <a href="{{ route('backups.portal.download', ['identifier' => $srv['uuid_short']]) }}" class="btn btn-primary" style="font-size:0.85rem;padding:0.5rem 1rem;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="7 10 12 15 17 10"></polyline>
                                        <line x1="12" y1="15" x2="12" y2="3"></line>
                                    </svg>
                                    Download (.tar.gz)
                                </a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div style="background:var(--bg-card);border:1px solid var(--border-subtle);border-radius:12px;padding:2.5rem;text-align:center;">
                        <p style="color:var(--text-muted);margin-bottom:0.75rem;">No active servers found on this account.</p>
                        <p style="font-size:0.85rem;color:var(--text-dim);">If you have a server ID from a shared server or previous snapshot, use the search box above to download it!</p>
                    </div>
                @endif
            </section>
        @endif
    </main>

    <footer class="footer">
        <p>&copy; {{ date('Y') }} plugged.host &bull; Game Server Hosting &bull; <a href="https://panel.plugged.host" target="_blank">Panel</a> &bull; <a href="https://status.plugged.host" target="_blank">Status</a> &bull; <a href="https://discord.gg/TYUrdQbYFt" target="_blank">Discord Support</a></p>
    </footer>

    <script>
        async function handleSearch(e) {
            e.preventDefault();
            const input = document.getElementById('serverIdInput');
            const errorBox = document.getElementById('searchError');
            const resultBox = document.getElementById('resultBox');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');

            const query = input.value.trim();
            if (!query) return;

            errorBox.style.display = 'none';
            resultBox.classList.remove('active');
            btnText.style.display = 'none';
            btnSpinner.style.display = 'inline-block';

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const response = await fetch('/lookup', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ server_id: query })
                });

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    errorBox.textContent = data.message || 'No backup found for this server ID.';
                    errorBox.style.display = 'block';
                    return;
                }

                const srv = data.server;
                document.getElementById('resultName').textContent = srv.name;
                document.getElementById('resultEgg').textContent = srv.egg;
                document.getElementById('resultNode').textContent = 'Node: ' + srv.node;
                document.getElementById('resultShortId').textContent = 'ID: ' + srv.uuid_short;
                document.getElementById('resultFullUuid').textContent = srv.uuid;

                const sizeMb = srv.bytes ? (srv.bytes / (1024 * 1024)).toFixed(1) + ' MB' : 'Active mirror';
                document.getElementById('resultMetaDetails').textContent = 'Size: ~' + sizeMb + ' • Excluded logs, node_modules & caches';

                const dlBtn = document.getElementById('resultDownloadBtn');
                dlBtn.href = srv.download_url;

                resultBox.classList.add('active');
            } catch (err) {
                errorBox.textContent = 'An error occurred while looking up the server. Please try again.';
                errorBox.style.display = 'block';
            } finally {
                btnText.style.display = 'inline';
                btnSpinner.style.display = 'none';
            }
        }
    </script>
</body>
</html>

