{{-- See PluggedHost\Core\Providers\ConsoleResiliencePluginProvider for what this recovers and why. --}}
@verbatim
<script data-plugged-host="console-resilience">
    (() => {
        if (window.pluggedHostConsoleResilience) {
            return;
        }

        window.pluggedHostConsoleResilience = true;

        const NativeWebSocket = window.WebSocket;

        // The daemon's console endpoint, and nothing else on the page: broadcasting and
        // any third-party socket must keep the browser's own implementation.
        const CONSOLE_URL = /^wss?:\/\/[^/]+\/api\/servers\/[^/]+\/ws(?:[?#]|$)/i;

        // Backoff between attempts, doubling from the first towards the last.
        const RETRY_MIN = 800;
        const RETRY_MAX = 15000;

        // Frames held while the connection is down. A queued power action is the whole
        // difference between "I clicked stop and nothing happened" and a stop.
        const QUEUE_MAX = 10;

        // ...but only while the click still means what the user meant. A start delivered
        // a minute after the fact is a surprise, not a fix.
        const QUEUE_TTL = 20000;

        // Consecutive failed attempts before the user is told. Below this a blip -- a
        // wings restart, a wifi hop, a laptop waking up -- heals itself in silence.
        const NOTIFY_AFTER = 3;

        // How long a connection may sit without a single frame before it is treated as dead
        // even though readyState still claims OPEN -- the state a socket is left in when the
        // machine sleeps or the network moves under it. A running server sends stats every
        // second, so this can only be reached by an idle one, where the cost of being wrong
        // is a single extra handshake and a short log replay.
        const STALE_AFTER = 90000;

        const warn = (...args) => console.warn('[plugged.host]', ...args);

        // ---- 1. The renderer that takes the console down with it ---------------------
        //
        // An addon that does nothing, for a browser where the real one cannot be built.
        // loadAddon() only requires activate() and dispose(); xterm then keeps its own DOM
        // renderer, which is what every console had before WebGL was an option.
        const inertAddon = () => ({
            activate() {},
            dispose() {},
            clearTextureAtlas() {},
            onContextLoss: () => ({ dispose() {} }),
        });

        const guardWebgl = (bundle) => {
            const Native = bundle && bundle.WebglAddon;

            if (typeof Native !== 'function' || Native.pluggedHostGuarded) {
                return bundle;
            }

            Native.pluggedHostGuarded = true;

            const nativeActivate = Native.prototype.activate;

            // Patching the prototype rather than only wrapping the class is what catches the
            // throw that matters. activate() defers when the terminal has no element yet --
            // `this._register(core.onWillOpen(() => this.activate(terminal)))` -- so the
            // WebglRenderer is really built *inside* terminal.open(), and because the deferred
            // call goes back through `this.activate` it lands in this try as well.
            Native.prototype.activate = function (terminal) {
                try {
                    nativeActivate.call(this, terminal);
                } catch (error) {
                    warn('WebGL2 is unavailable; the console is using xterm\'s DOM renderer.', error);

                    // The renderer throws before it appends its canvas, so there is nothing
                    // left in the DOM -- only the addon's own disposables to release.
                    try {
                        this.dispose();
                    } catch (ignored) {
                        // Never activated, so there is nothing to tear down.
                    }

                    return;
                }

                if (this.pluggedHostWatchingContext) {
                    return;
                }

                this.pluggedHostWatchingContext = true;

                // Upstream never handles this. A GPU reset, a driver crash or one tab too
                // many costs the page its WebGL context, and the canvas then freezes with the
                // last frame it drew -- a console that looks live and is not. Disposing the
                // addon runs xterm's own fallback, which puts the DOM renderer back.
                try {
                    this.onContextLoss(() => {
                        warn('The WebGL context was lost; falling back to the DOM renderer.');

                        try {
                            this.dispose();
                        } catch (ignored) {
                            // Already disposed.
                        }
                    });
                } catch (ignored) {
                    // No such event on this build; the guard above is the part that matters.
                }
            };

            // The constructor throws too, on Safari below 16, and that one happens on the
            // line before terminal.open() -- early enough to take the whole @script block
            // with it, socket included.
            const Guarded = function (...args) {
                try {
                    return new Native(...args);
                } catch (error) {
                    warn('The WebGL addon could not be constructed; using the DOM renderer.', error);

                    return inertAddon();
                }
            };

            Guarded.pluggedHostGuarded = true;
            bundle.WebglAddon = Guarded;

            return bundle;
        };

        // ---- 2. A terminal that fits a phone ----------------------------------------
        //
        // Upstream builds the terminal at the user's font size, 14px unless they changed it in
        // their profile, which leaves a phone about 37 columns and wraps nearly every log line.
        // Below Filament's `sm` breakpoint that default becomes 12px; a size somebody picked
        // themselves is left alone. The rest of the phone layout is CSS, in theme v2.
        const PHONE = '(max-width: 39.99rem)';
        const UPSTREAM_FONT_SIZE = 14; // CustomizationKey::ConsoleFontSize's default
        const PHONE_FONT_SIZE = 12;

        // Theme v2 pads the terminal and paints the padding with this colour. Upstream's is
        // rgba(19,26,32,0.7), which the WebGL canvas blends over whatever lies beneath it and
        // the DOM renderer shows over xterm's black, so each lands on a different shade. Opaque,
        // every layer agrees.
        const opaque = (color) => {
            const match = /^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\d.]+\s*\)$/i.exec(String(color || ''));

            return match ? `rgb(${match[1]}, ${match[2]}, ${match[3]})` : color;
        };

        const fitTerminal = (bundle) => {
            const Native = bundle && bundle.Terminal;

            if (typeof Native !== 'function' || Native.pluggedHostFitted) {
                return bundle;
            }

            class Terminal extends Native {
                constructor(options = {}, ...rest) {
                    const fitted = { ...options };

                    if (options.theme) {
                        fitted.theme = { ...options.theme, background: opaque(options.theme.background) };
                    }

                    if (options.fontSize === UPSTREAM_FONT_SIZE && window.matchMedia(PHONE).matches) {
                        fitted.fontSize = PHONE_FONT_SIZE;
                    }

                    super(fitted, ...rest);
                }
            }

            Terminal.pluggedHostFitted = true;
            bundle.Terminal = Terminal;

            return bundle;
        };

        // resources/js/console.js assigns window.Xterm once, from a module, so it normally
        // lands after this script has run: intercept the assignment rather than race it.
        const prepare = (bundle) => {
            try {
                guardWebgl(bundle);
            } catch (error) {
                warn('Could not guard the WebGL addon.', error);
            }

            try {
                fitTerminal(bundle);
            } catch (error) {
                warn('Could not fit the terminal to this screen.', error);
            }
        };

        if (window.Xterm) {
            prepare(window.Xterm);
        } else {
            let bundle;

            Object.defineProperty(window, 'Xterm', {
                configurable: true,
                enumerable: true,
                get: () => bundle,
                set: (value) => {
                    bundle = value;
                    prepare(value);
                },
            });
        }

        // ---- 3. A console socket that comes back ------------------------------------
        //
        // The one live console connection. A new console page retires the previous one:
        // under wire:navigate the old @script closure survives the page that created it,
        // so without this a walk through the panel would leave a reconnecting socket
        // behind at every visit.
        let current = null;

        class ResilientConsoleSocket {
            constructor(url, protocols) {
                this.url = url;
                this.protocols = protocols;

                this.onopen = null;
                this.onmessage = null;
                this.onerror = null;
                this.onclose = null;

                this.socket = null;
                this.timer = null;
                this.queue = [];
                this.attempts = 0;
                this.failures = 0;
                this.authenticated = false;
                this.notified = false;
                this.retired = false;
                this.lastFrameAt = Date.now();
                this.lastStatsAt = 0;

                if (current && current !== this) {
                    current.retire();
                }

                current = this;

                this.connect();
            }

            get readyState() {
                return this.socket ? this.socket.readyState : NativeWebSocket.CONNECTING;
            }

            get bufferedAmount() {
                return this.socket ? this.socket.bufferedAmount : 0;
            }

            connect() {
                clearTimeout(this.timer);
                this.timer = null;
                this.authenticated = false;

                let socket;

                try {
                    socket = this.protocols === undefined
                        ? new NativeWebSocket(this.url)
                        : new NativeWebSocket(this.url, this.protocols);
                } catch (error) {
                    warn('Could not open the console socket.', error);
                    this.failures++;
                    this.scheduleReconnect();

                    return;
                }

                this.socket = socket;

                socket.onopen = (event) => {
                    this.attempts = 0;
                    this.failures = 0;
                    this.lastFrameAt = Date.now();
                    this.clearNotice();

                    // Upstream's own handler answers this by asking the panel for a fresh
                    // websocket JWT, which is why a reconnect needs no token of its own --
                    // and why the ten-minute expiry cannot outlive a reconnect.
                    if (this.onopen) {
                        this.onopen(event);
                    }
                };

                socket.onmessage = (event) => {
                    this.lastFrameAt = Date.now();

                    // wings rejects anything sent before the handshake, so the queue waits
                    // for this rather than for readyState. The flag is a latch, not a test:
                    // send() gates every non-auth frame on it, so leaving it false would
                    // queue the whole session and the console would send nothing at all.
                    if (!this.authenticated && typeof event.data === 'string' && (event.data.includes('"event":"auth success"') || event.data.includes('"event": "auth success"'))) {
                        this.authenticated = true;
                        this.flush();
                    }

                    // Performance optimization: Wings pushes stats frames every 1s, which triggers
                    // a Livewire HTTP update roundtrip per frame. Drop them when tab is hidden, and
                    // throttle to at most once per 5s when visible.
                    if (typeof event.data === 'string' && (event.data.includes('"event":"stats"') || event.data.includes('"event": "stats"'))) {
                        if (document.hidden) {
                            return;
                        }
                        const now = Date.now();
                        if (now - this.lastStatsAt < 5000) {
                            return;
                        }
                        this.lastStatsAt = now;
                    }

                    if (this.onmessage) {
                        this.onmessage(event);
                    }
                };

                socket.onerror = (event) => {
                    if (this.failures + 1 >= NOTIFY_AFTER) {
                        this.notify(event);
                    }
                };

                socket.onclose = (event) => {
                    if (this.retired) {
                        if (this.onclose) {
                            this.onclose(event);
                        }

                        return;
                    }

                    this.failures++;
                    this.scheduleReconnect();
                };
            }

            /**
             * The console page sends four kinds of frame: auth, send logs, send command and
             * set state. Only the last two are worth holding for a connection that is on its
             * way back -- a token minted for a socket that has already gone is useless, and
             * the log replay comes with the handshake anyway.
             */
            send(data) {
                const text = typeof data === 'string' ? data : '';
                const isAuth = text.includes('"event":"auth"') || text.includes('"event": "auth"');
                const isPower = text.includes('"event":"set state"') || text.includes('"event": "set state"');

                // wings closes the connection on anything that arrives before the handshake,
                // so the auth frame itself is the only one allowed through ahead of it.
                if (this.socket && this.socket.readyState === NativeWebSocket.OPEN && (this.authenticated || isAuth)) {
                    this.socket.send(data);

                    return;
                }

                if (isAuth || this.retired) {
                    return;
                }

                // Clicking stop and then kill while the connection is down should kill, not
                // stop and then kill, so only the newest power frame survives.
                if (isPower) {
                    this.queue = this.queue.filter((item) => !item.data.includes('"event":"set state"') && !item.data.includes('"event": "set state"'));
                }

                this.queue.push({ data, at: Date.now() });

                // A long outage must not turn into a burst of stale commands on reconnect.
                while (this.queue.length > QUEUE_MAX) {
                    this.queue.shift();
                }

                // A click on a dead console is the clearest possible signal that the user
                // wants it back, so it also shortcuts the backoff.
                this.nudge();
            }

            flush() {
                const now = Date.now();
                const pending = this.queue.filter((item) => now - item.at < QUEUE_TTL);

                this.queue = [];

                for (const item of pending) {
                    try {
                        this.socket.send(item.data);
                    } catch (error) {
                        warn('Could not replay a queued console frame.', error);
                    }
                }
            }

            scheduleReconnect() {
                if (this.retired || this.timer) {
                    return;
                }

                // The console page has left the DOM, so nobody is listening any more. Without
                // this a socket kept reconnecting for the rest of the SPA session.
                if (!document.getElementById('terminal')) {
                    this.retire();

                    return;
                }

                const backoff = Math.min(RETRY_MAX, RETRY_MIN * 2 ** this.attempts);

                this.attempts++;
                this.timer = setTimeout(() => {
                    this.timer = null;
                    this.connect();
                }, backoff * (0.7 + Math.random() * 0.6));
            }

            /** Skip whatever is left of the backoff, on a deliberate nudge. */
            reconnectNow() {
                if (this.retired || (this.socket && this.socket.readyState === NativeWebSocket.OPEN)) {
                    return;
                }

                if (this.socket && this.socket.readyState === NativeWebSocket.CONNECTING) {
                    return;
                }

                clearTimeout(this.timer);
                this.timer = null;
                this.attempts = 0;
                this.connect();
            }

            /** OPEN, but nothing has arrived for long enough that it cannot be true. */
            isStalled() {
                return this.socket
                    && this.socket.readyState === NativeWebSocket.OPEN
                    && Date.now() - this.lastFrameAt > STALE_AFTER;
            }

            /**
             * Drop a connection the browser still believes in and start a new one. The
             * handlers come off first so this cannot be counted as a failure or schedule a
             * second reconnect on top of the one below.
             */
            refresh() {
                if (this.retired) {
                    return;
                }

                const socket = this.socket;

                this.socket = null;

                if (socket) {
                    socket.onopen = null;
                    socket.onmessage = null;
                    socket.onerror = null;
                    socket.onclose = null;

                    try {
                        socket.close();
                    } catch (ignored) {
                        // Already gone, which is the case this exists for.
                    }
                }

                clearTimeout(this.timer);
                this.timer = null;
                this.attempts = 0;
                this.connect();
            }

            /** Ask for the connection back, whichever way it is broken. */
            nudge() {
                if (this.isStalled()) {
                    this.refresh();

                    return;
                }

                this.reconnectNow();
            }

            /**
             * Upstream raises its red "connection to the daemon failed" banner from the very
             * first socket error. With reconnection that would flash on every hiccup, so it
             * is raised once an outage is real and taken back down when it ends. Both halves
             * go through the same events the console page already uses.
             */
            notify(event) {
                if (this.notified) {
                    return;
                }

                this.notified = true;

                if (this.onerror) {
                    this.onerror(event);
                }
            }

            clearNotice() {
                if (!this.notified) {
                    return;
                }

                this.notified = false;

                try {
                    window.Livewire.dispatch('removeAlertBanner', { id: 'websocket_error' });
                } catch (error) {
                    warn('Could not clear the console connection banner.', error);
                }
            }

            /** Stop for good: the page that owned this connection is gone. */
            retire() {
                this.retired = true;
                this.queue = [];
                clearTimeout(this.timer);
                this.timer = null;

                try {
                    if (this.socket) {
                        this.socket.close();
                    }
                } catch (ignored) {
                    // Already closed.
                }
            }

            close(code, reason) {
                this.retired = true;
                this.queue = [];
                clearTimeout(this.timer);
                this.timer = null;

                try {
                    if (this.socket) {
                        this.socket.close(code, reason);
                    }
                } catch (ignored) {
                    // Already closed.
                }
            }
        }

        // Only the daemon's console endpoint is replaced. Everything else on the page --
        // broadcasting, anything a browser extension opens -- keeps the real constructor.
        const ConsoleWebSocket = function (url, protocols) {
            if (typeof url === 'string' && CONSOLE_URL.test(url)) {
                return new ResilientConsoleSocket(url, protocols);
            }

            return protocols === undefined
                ? new NativeWebSocket(url)
                : new NativeWebSocket(url, protocols);
        };

        ConsoleWebSocket.prototype = NativeWebSocket.prototype;

        for (const constant of ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED']) {
            ConsoleWebSocket[constant] = NativeWebSocket[constant];
        }

        window.WebSocket = ConsoleWebSocket;

        const nudge = () => {
            if (current && !current.retired) {
                current.nudge();
            }
        };

        // The three moments a connection is most likely to be found dead, and the only ones
        // where asking costs nothing.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                nudge();
            }
        });

        window.addEventListener('online', nudge);
        window.addEventListener('pageshow', nudge);

        // wire:navigate replaces the body but not the closure the console page left behind,
        // so somebody has to hang up on its behalf.
        document.addEventListener('livewire:navigated', () => {
            if (current && !current.retired && !document.getElementById('terminal')) {
                current.retire();
            }
        });

        // ---- 4. A command line the phone keyboard leaves alone ----------------------
        //
        // Brigadier's literals are case-sensitive, so a keyboard that turns "say" into "Say"
        // turns a command into "Unknown or incomplete command", and autocorrect does the same
        // to player names. The input is upstream's, so the attributes go on here -- and onto
        // the incoming markup before every morph, because each re-render of the console widget
        // (a stats frame every few seconds) would otherwise strip what its HTML does not carry.
        const COMMAND_ATTRIBUTES = {
            autocapitalize: 'none',
            autocomplete: 'off',
            autocorrect: 'off',
            spellcheck: 'false',
            enterkeyhint: 'send',
        };

        const dressCommandInput = (input) => {
            for (const [name, value] of Object.entries(COMMAND_ATTRIBUTES)) {
                if (input.getAttribute(name) !== value) {
                    input.setAttribute(name, value);
                }
            }
        };

        const dressCurrentCommandInput = () => {
            const input = document.getElementById('send-command');

            if (input) {
                dressCommandInput(input);
            }
        };

        const watchMorphs = () => {
            window.Livewire.hook('morph.updating', ({ el, toEl }) => {
                if (el && el.id === 'send-command' && toEl && toEl.nodeType === Node.ELEMENT_NODE) {
                    dressCommandInput(toEl);
                }
            });
        };

        // A full load runs this before Livewire exists; arriving by wire:navigate, after.
        if (window.Livewire) {
            watchMorphs();
        } else {
            document.addEventListener('livewire:init', watchMorphs, { once: true });
        }

        dressCurrentCommandInput();
        document.addEventListener('livewire:navigated', dressCurrentCommandInput);
    })();
</script>
@endverbatim
