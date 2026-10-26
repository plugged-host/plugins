{{-- A location's ping as the visitor sees it: the browser times small requests to the location's
     nodes (LocationCatalog's `probes`, each node's Wings address). The first request to a node
     also opens the connection (DNS, TCP, TLS) and is left out; the fastest of the next three is
     about one round trip. A location's ping is its fastest node's. Each node is measured once per
     page load (the promise is kept), so a re-render or a second list does not ask again.
     The answer is opaque (no-cors): only the time is read, never the response. --}}
<script>
    window.phLocationPings ??= (() => {
        const nodes = new Map();

        const once = async (url) => {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 5000);
            const started = performance.now();

            try {
                await fetch(url, { mode: 'no-cors', cache: 'no-store', credentials: 'omit', signal: controller.signal });

                return performance.now() - started;
            } catch (e) {
                return null;
            } finally {
                clearTimeout(timer);
            }
        };

        const node = async (url) => {
            if ((await once(url)) === null) {
                return null;
            }

            const times = [];
            for (let i = 0; i < 3; i++) {
                const ms = await once(url);
                if (ms !== null) {
                    times.push(ms);
                }
            }

            return times.length ? Math.max(1, Math.round(Math.min(...times))) : null;
        };

        return {
            /** The fastest node's ping in ms, or null when none answered. */
            async measure(urls) {
                const times = await Promise.all((urls || []).map((url) => {
                    if (!nodes.has(url)) {
                        nodes.set(url, node(url));
                    }

                    return nodes.get(url);
                }));
                const answered = times.filter((ms) => ms !== null);

                return answered.length ? Math.min(...answered) : null;
            },

            /** The Pelican signal meter's steps: four bars under 40 ms, one from 150. */
            bars(ms) {
                return ms < 40 ? 4 : ms < 80 ? 3 : ms < 150 ? 2 : 1;
            },

            color(ms) {
                return ['var(--danger-500)', 'var(--warning-500)', 'var(--success-400)', 'var(--success-500)'][this.bars(ms) - 1];
            },
        };
    })();
</script>
