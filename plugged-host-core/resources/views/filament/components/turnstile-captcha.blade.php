@php
    $statePath = $getStatePath();
    $fieldWrapperView = $getFieldWrapperView();
    $siteKey = config('captcha.turnstile.site_key') ?: env('CAPTCHA_TURNSTILE_SITE_KEY');
@endphp

<x-dynamic-component class="flex justify-center" :component="$fieldWrapperView" :field="$turnstile ?? $field ?? $this">
    <div
        wire:ignore
        x-data="{
            widgetId: null,
            init() {
                const renderCaptcha = () => {
                    if (!this.$refs.turnstileContainer) {
                        return;
                    }
                    if (this.widgetId !== null && typeof window.turnstile !== 'undefined') {
                        try {
                            window.turnstile.remove(this.widgetId);
                        } catch (e) {}
                        this.widgetId = null;
                    }
                    if (typeof window.turnstile !== 'undefined' && typeof window.turnstile.render === 'function') {
                        try {
                            this.widgetId = window.turnstile.render(this.$refs.turnstileContainer, {
                                sitekey: '{{ $siteKey }}',
                                callback: (token) => {
                                    $wire.set('{{ $statePath }}', token);
                                },
                                'error-callback': () => {
                                    $wire.set('{{ $statePath }}', null);
                                },
                                'expired-callback': () => {
                                    $wire.set('{{ $statePath }}', null);
                                },
                            });
                        } catch (e) {
                            console.error('Turnstile render error:', e);
                        }
                    }
                };

                if (typeof window.turnstile !== 'undefined' && typeof window.turnstile.render === 'function') {
                    renderCaptcha();
                } else {
                    window.__turnstileCallbacks = window.__turnstileCallbacks || [];
                    window.__turnstileCallbacks.push(renderCaptcha);

                    if (typeof window.onloadTurnstileCallback !== 'function') {
                        window.onloadTurnstileCallback = () => {
                            if (window.__turnstileCallbacks) {
                                while (window.__turnstileCallbacks.length > 0) {
                                    const cb = window.__turnstileCallbacks.shift();
                                    try {
                                        cb();
                                    } catch (err) {
                                        console.error(err);
                                    }
                                }
                            }
                        };
                    }

                    let attempts = 0;
                    const pollTimer = setInterval(() => {
                        attempts++;
                        if (this.widgetId !== null || attempts > 20) {
                            clearInterval(pollTimer);
                            return;
                        }
                        if (typeof window.turnstile !== 'undefined' && typeof window.turnstile.render === 'function') {
                            clearInterval(pollTimer);
                            renderCaptcha();
                        }
                    }, 250);
                    this.$cleanup(() => clearInterval(pollTimer));
                }

                Livewire.on('reset-captcha', () => {
                    if (this.widgetId !== null && typeof window.turnstile !== 'undefined') {
                        try {
                            window.turnstile.reset(this.widgetId);
                        } catch (e) {}
                    }
                });

                this.$cleanup(() => {
                    if (this.widgetId !== null && typeof window.turnstile !== 'undefined') {
                        try {
                            window.turnstile.remove(this.widgetId);
                        } catch (e) {}
                        this.widgetId = null;
                    }
                });
            }
        }"
    >
        <div data-sitekey="{{ $siteKey }}" x-ref="turnstileContainer"></div>
    </div>

    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback" defer></script>
    @endonce
</x-dynamic-component>
