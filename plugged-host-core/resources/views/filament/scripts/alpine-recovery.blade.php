{{-- See PluggedHost\Core\Providers\AlpineRecoveryPluginProvider for what this recovers and why. --}}
@verbatim
<script data-plugged-host="alpine-recovery">
    (() => {
        if (window.pluggedHostAlpineRecovery) {
            return;
        }

        window.pluggedHostAlpineRecovery = true;

        // Passes after Livewire settles. The first two catch stale x-cloak, which needs
        // no grace period; the later ones are what a stranded x-load container needs.
        const PASSES = [0, 300, 900, 2000];

        // How long a container may sit with an unfinished handshake before we assume it
        // is stranded rather than still downloading. Anything shorter would restart
        // perfectly healthy components on a slow connection.
        const GRACE = 600;

        const stalledSince = new WeakMap();

        let timers = [];

        const alpine = () => window.Alpine;

        // Alpine only ever removes x-cloak, so an x-cloak still sitting inside a tree
        // Alpine has initialised is stale by definition. Containers waiting on their
        // module are excluded: their cloak is doing its job.
        const uncloak = () => {
            document.querySelectorAll('[x-cloak]').forEach((node) => {
                if (node.closest('[x-ignore]')) {
                    return;
                }

                const root = node.closest('[x-data]');

                if (!root || !root._x_dataStack) {
                    return;
                }

                node.removeAttribute('x-cloak');
            });
        };

        const handshakeComplete = (el) => !!el._x_dataStack
            && el._x_ignore !== true
            && !el.hasAttribute('x-ignore');

        const revive = (el) => {
            const Alpine = alpine();

            try {
                Alpine.destroyTree(el);
            } catch (error) {
                // Never initialised, so there is nothing to tear down.
            }

            // Clearing the marker is the whole point: while it is set, x-load returns
            // early and the subtree stays ignored no matter how often we re-init.
            delete el._x_async;
            el._x_ignore = false;
            el.removeAttribute('x-ignore');

            Alpine.initTree(el);
        };

        const sweep = () => {
            if (!alpine()?.initTree) {
                return;
            }

            uncloak();

            document.querySelectorAll('[x-load]').forEach((el) => {
                // An enclosing container is still resolving. Its own activation will walk
                // into this one, so this element is waiting rather than stranded.
                if (el.parentElement?.closest('[x-ignore]')) {
                    return;
                }

                if (handshakeComplete(el)) {
                    stalledSince.delete(el);

                    return;
                }

                const since = stalledSince.get(el);

                if (since === undefined) {
                    stalledSince.set(el, performance.now());

                    return;
                }

                if (performance.now() - since < GRACE) {
                    return;
                }

                stalledSince.delete(el);

                try {
                    revive(el);
                } catch (error) {
                    console.warn('Could not re-initialise a deferred Alpine component.', el, error);
                }
            });
        };

        const schedule = () => {
            timers.forEach(clearTimeout);
            timers = PASSES.map((delay) => setTimeout(sweep, delay));
        };

        document.addEventListener('livewire:navigated', schedule);

        document.addEventListener('livewire:initialized', () => {
            window.Livewire?.hook?.('morphed', schedule);
            window.Livewire?.hook?.('morph.added', schedule);

            schedule();
        });
    })();
</script>
@endverbatim
