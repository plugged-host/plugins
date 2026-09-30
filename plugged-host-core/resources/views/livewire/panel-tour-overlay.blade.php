{{--
    The welcome tour over the real panel (PluggedHost\Core\Livewire\PanelTourOverlay).

    Three layers, all position: fixed above the panel (z-index 45: over the sidebar and
    Filament's modals, under its notifications):

    - .ph-tour-blocker, a transparent sheet that takes every click, so the page cannot be used
      mid-tour and the person stays on the page the step is about;
    - .ph-tour-hole, a box around the element being explained. Its huge outline *is* the dim
      (the same gray-950/75 as Filament's modal backdrop) and the box itself stays clear, so
      the real element shows through with the accent ring around it. With no element (the
      welcome step, or a target that is not on the page) it shrinks to the middle and the
      outline covers everything;
    - .ph-tour-card, the step itself: beside the element on wide screens, a sheet at the top
      or bottom on phones, centred and wider when there is nothing to point at.

    Alpine owns only the geometry. The copy and the buttons are Livewire's: each step's body is
    keyed by part and step, so the morph replaces it on every transition and its x-init hands
    the new targets to show(). The Alpine root is not keyed, so the hole and the card glide
    from one element to the next instead of blinking. Positions are written straight to the
    hole's and the card's style, and both are wire:ignore(.self): a Livewire morph matches
    attributes to the server's HTML, which has no style, and would snap them back to the
    middle on every "Next".

    The <style> sits in the body for the reason plugged-host.css documents: wire:navigate
    never removes a <style> it added to <head>. Colours are theme v2 tokens only; the card is
    the modal window (surface, radius, elevation), the ring is the accent's focus-ring rung.
--}}
<div>
    @if ($current)
        <style>
            .ph-tour {
                --ph-tour-shade: color-mix(in oklab, var(--gray-950, #0b0d10) 75%, transparent);
                --ph-tour-ease: cubic-bezier(0.2, 0, 0, 1);
                position: fixed;
                inset: 0;
                z-index: 45;
            }

            .ph-tour-blocker {
                position: absolute;
                inset: 0;
            }

            .ph-tour-hole {
                position: fixed;
                top: var(--ph-hole-y, 50vh);
                left: var(--ph-hole-x, 50vw);
                width: var(--ph-hole-w, 0px);
                height: var(--ph-hole-h, 0px);
                box-sizing: border-box;
                border-radius: 0.75rem;
                border: 2px solid var(--phv2-accent-bright);
                outline: 200vmax solid var(--ph-tour-shade);
                pointer-events: none;
                transition: top 320ms var(--ph-tour-ease), left 320ms var(--ph-tour-ease),
                    width 320ms var(--ph-tour-ease), height 320ms var(--ph-tour-ease),
                    border-color 200ms ease;
                animation: ph-tour-fade 200ms ease-out both;
            }

            .ph-tour-hole-closed {
                border-color: transparent;
            }

            .ph-tour-card {
                position: fixed;
                top: 0;
                left: 0;
                display: flex;
                flex-direction: column;
                gap: 1.25rem;
                width: min(26rem, calc(100vw - 2rem));
                max-height: var(--ph-card-max, calc(100dvh - 2rem));
                overflow: hidden;
                padding: 1.5rem;
                border-radius: 0.75rem;
                background-color: var(--phv2-modal);
                box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.5), 0 0 0 1px var(--phv2-hairline);
                color: var(--phv2-text);
                transform: translate(var(--ph-card-x, 0px), var(--ph-card-y, 0px));
                transition: transform 320ms var(--ph-tour-ease), opacity 200ms ease;
                opacity: 0;
            }

            .ph-tour-card.ph-tour-ready {
                opacity: 1;
            }

            /* Set for one frame when the card changes layout, so it appears in place rather
               than flying in from where the previous layout had it. */
            .ph-tour-card.ph-tour-jump {
                transition: opacity 200ms ease;
            }

            /* Nothing to point at: centred, and wider -- this is the step people read. */
            .ph-tour-card[data-mode='center'] {
                top: 50%;
                left: 50%;
                width: min(36rem, calc(100vw - 2rem));
                transform: translate(-50%, -50%);
                transition: opacity 200ms ease;
            }

            /* Phones: a sheet across the screen, on the side the element is not on. */
            .ph-tour-card[data-mode='sheet-bottom'],
            .ph-tour-card[data-mode='sheet-top'] {
                left: 1rem;
                right: 1rem;
                width: auto;
                transform: none;
                transition: opacity 200ms ease;
            }

            .ph-tour-card[data-mode='sheet-bottom'] {
                top: auto;
                bottom: 1rem;
            }

            .ph-tour-card[data-mode='sheet-top'] {
                top: 1rem;
            }

            /* Only the copy scrolls when the card is short (a phone sheet next to a tall
               element); the progress row and the buttons always stay on screen. */
            .ph-tour-step {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                min-height: 0;
                overflow-y: auto;
                animation: ph-tour-step-in 260ms ease-out both;
            }

            .ph-tour-progress,
            .ph-tour-actions {
                flex-shrink: 0;
            }

            .ph-tour-progress {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                font-size: 0.75rem;
                line-height: 1rem;
                font-variant-numeric: tabular-nums;
                color: var(--phv2-text-muted);
            }

            .ph-tour-dots {
                display: flex;
                align-items: center;
                gap: 0.375rem;
            }

            .ph-tour-dot {
                display: block;
                height: 0.375rem;
                width: 0.375rem;
                border-radius: 9999px;
                background-color: var(--phv2-raise-2);
                transition: width 200ms ease, background-color 200ms ease;
            }

            .ph-tour-dot-done {
                background-color: var(--phv2-accent);
            }

            .ph-tour-dot-current {
                width: 1.5rem;
                background-color: var(--phv2-accent-bright);
            }

            .ph-tour-heading {
                font-size: 1.125rem;
                line-height: 1.75rem;
                font-weight: 600;
                color: var(--phv2-text);
            }

            .ph-tour-card[data-mode='center'] .ph-tour-heading {
                font-size: 1.5rem;
                line-height: 2rem;
            }

            .ph-tour-body {
                font-size: 0.875rem;
                line-height: 1.625;
                color: var(--phv2-text-muted);
            }

            .ph-tour-bullets {
                display: flex;
                flex-direction: column;
                gap: 0.375rem;
                margin-top: 0.25rem;
                padding-inline-start: 1.25rem;
                list-style: disc;
                font-size: 0.875rem;
                line-height: 1.5;
                color: var(--phv2-text-nav);
            }

            .ph-tour-bullets > li::marker {
                color: var(--phv2-text-faint);
            }

            /* One row that wraps: when a long primary label does not fit next to Back, it drops
               to its own line and stays on the right. Buttons keep theme v2's auto width. */
            .ph-tour-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
            }

            .ph-tour-actions-start {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }

            .ph-tour-actions-end {
                display: flex;
                margin-inline-start: auto;
            }

            @keyframes ph-tour-step-in {
                from { opacity: 0; transform: translateY(0.375rem); }
                to { opacity: 1; transform: none; }
            }

            @keyframes ph-tour-fade {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            @media (prefers-reduced-motion: reduce) {
                .ph-tour-hole,
                .ph-tour-card,
                .ph-tour-dot {
                    transition: none;
                }

                .ph-tour-hole,
                .ph-tour-step {
                    animation: none;
                }
            }
        </style>

        <div
            class="ph-tour"
            x-data="{
                targets: [],
                sidebar: false,
                target: null,
                hole: null,
                mode: 'center',
                placedMode: null,
                ready: false,
                openedSidebar: false,
                frame: null,
                timer: null,
                pending: null,

                init() {
                    this.onViewport = () => this.queue()
                    window.addEventListener('resize', this.onViewport)
                    window.addEventListener('scroll', this.onViewport, true)
                    {{-- Lazy server cards, the balance poll and fonts all move things after load. --}}
                    this.timer = setInterval(() => this.measure(), 500)
                },

                destroy() {
                    window.removeEventListener('resize', this.onViewport)
                    window.removeEventListener('scroll', this.onViewport, true)
                    clearInterval(this.timer)
                    clearTimeout(this.pending)
                    this.restoreSidebar()
                },

                {{-- Called by each step's x-init with that step's selectors. --}}
                show(targets, sidebar) {
                    this.targets = targets
                    this.sidebar = sidebar
                    this.target = null
                    clearTimeout(this.pending)
                    this.pending = setTimeout(() => this.reveal(), this.prepareSidebar())
                },

                wide() {
                    return window.matchMedia('(min-width: 64rem)').matches
                },

                {{-- Steps about the sidebar open it on phones (and when it is collapsed), and the
                     tour closes it again once it is done with it. Returns how long to wait for
                     the slide-in before measuring. --}}
                prepareSidebar() {
                    const store = Alpine.store('sidebar')

                    if (! store) {
                        return 0
                    }

                    if (this.sidebar && ! store.isOpen) {
                        store.open()
                        this.openedSidebar = true

                        return 320
                    }

                    if (! this.sidebar && this.openedSidebar && ! this.wide()) {
                        this.restoreSidebar()

                        return 320
                    }

                    return 0
                },

                restoreSidebar() {
                    if (this.openedSidebar) {
                        Alpine.store('sidebar')?.close()
                        this.openedSidebar = false
                    }
                },

                find() {
                    for (const selector of this.targets) {
                        let nodes = []

                        try {
                            nodes = document.querySelectorAll(selector)
                        } catch (error) {
                            continue
                        }

                        for (const node of nodes) {
                            if (this.$root.contains(node)) {
                                continue
                            }

                            const rect = node.getBoundingClientRect()

                            if (rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth) {
                                return node
                            }
                        }
                    }

                    return null
                },

                reveal() {
                    this.target = this.find()

                    if (this.target) {
                        const rect = this.target.getBoundingClientRect()

                        if (rect.top < 0 || rect.bottom > window.innerHeight) {
                            this.target.scrollIntoView({
                                block: rect.height > window.innerHeight * 0.6 ? 'start' : 'center',
                                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                            })
                        }
                    }

                    this.measure()
                    this.$nextTick(() => this.$refs.primary?.focus({ preventScroll: true }))
                },

                queue() {
                    if (this.frame) {
                        return
                    }

                    this.frame = requestAnimationFrame(() => {
                        this.frame = null
                        this.measure()
                    })
                },

                measure() {
                    if (this.targets.length && ! this.target?.isConnected) {
                        this.target = this.find()
                    }

                    const width = window.innerWidth
                    const height = window.innerHeight
                    let hole = null

                    if (this.target) {
                        const rect = this.target.getBoundingClientRect()
                        const x = Math.max(4, rect.left - 8)
                        const y = Math.max(4, rect.top - 8)
                        const w = Math.min(width - 4, rect.right + 8) - x
                        const h = Math.min(height - 4, rect.bottom + 8) - y

                        hole = w > 8 && h > 8 ? { x, y, w, h } : null
                    }

                    this.hole = hole
                    this.mode = hole ? 'anchored' : 'center'

                    const card = this.$refs.card

                    {{-- Phones: a sheet on whichever side of the element has more room, no taller
                         than that room unless it is tiny -- covering some of the element beats a
                         card too short to read. --}}
                    if (hole && width < 640) {
                        const above = hole.y - 32
                        const below = height - hole.y - hole.h - 32

                        this.mode = below >= above ? 'sheet-bottom' : 'sheet-top'
                        card.style.setProperty('--ph-card-max', Math.round(Math.max(above, below, height * 0.45)) + 'px')
                    } else {
                        card.style.removeProperty('--ph-card-max')
                    }

                    const style = this.$refs.hole.style

                    for (const [name, value] of Object.entries({ x: hole?.x, y: hole?.y, w: hole?.w, h: hole?.h })) {
                        hole ? style.setProperty('--ph-hole-' + name, Math.round(value) + 'px') : style.removeProperty('--ph-hole-' + name)
                    }

                    this.$refs.hole.classList.toggle('ph-tour-hole-closed', ! hole)
                    card.dataset.mode = this.mode

                    this.place()
                },

                {{-- Beside the element: right, below, left, above -- the first side the card
                     fits on whole, else inside the element's top-right corner (a console's
                     output starts top-left and its command line is at the bottom). --}}
                place() {
                    const card = this.$refs.card

                    if (! card) {
                        return
                    }

                    const jump = ! this.ready || this.placedMode !== this.mode

                    if (jump) {
                        card.classList.add('ph-tour-jump')
                    }

                    if (this.mode === 'anchored' && this.hole) {
                        const gap = 16
                        const margin = 16
                        const w = card.offsetWidth
                        const h = card.offsetHeight
                        const width = window.innerWidth
                        const height = window.innerHeight
                        const hole = this.hole
                        const sides = [
                            [hole.x + hole.w + gap, hole.y, hole.x + hole.w + gap + w <= width - margin],
                            [hole.x, hole.y + hole.h + gap, hole.y + hole.h + gap + h <= height - margin],
                            [hole.x - gap - w, hole.y, hole.x - gap - w >= margin],
                            [hole.x, hole.y - gap - h, hole.y - gap - h >= margin],
                        ]
                        const side = sides.find((candidate) => candidate[2])
                        const x = side ? side[0] : hole.x + hole.w - w - gap
                        const y = side ? side[1] : hole.y + gap

                        card.style.setProperty('--ph-card-x', Math.round(Math.min(Math.max(x, margin), width - margin - w)) + 'px')
                        card.style.setProperty('--ph-card-y', Math.round(Math.min(Math.max(y, margin), height - margin - h)) + 'px')
                    }

                    if (jump) {
                        {{-- Flush the jump before taking the class off, or the move animates anyway. --}}
                        card.offsetWidth
                        card.classList.remove('ph-tour-jump')
                    }

                    this.placedMode = this.mode
                    this.ready = true
                    card.classList.add('ph-tour-ready')
                },
            }"
            x-on:keydown.escape.window="if (@js($part === 'server') || $wire.step > 1) $wire.skip()"
        >
            <div class="ph-tour-blocker" aria-hidden="true"></div>

            <div x-ref="hole" class="ph-tour-hole ph-tour-hole-closed" wire:ignore aria-hidden="true"></div>

            <section
                x-ref="card"
                class="ph-tour-card"
                data-mode="center"
                wire:ignore.self
                x-trap.noautofocus="true"
                role="dialog"
                aria-modal="true"
                aria-labelledby="ph-tour-heading"
                aria-describedby="ph-tour-body"
            >
                {{-- Progress: a dot per step, the current one widened into a bar. --}}
                <div class="ph-tour-progress">
                    <div class="ph-tour-dots" aria-hidden="true">
                        @for ($i = 1; $i <= $count; $i++)
                            <span
                                @class([
                                    'ph-tour-dot',
                                    'ph-tour-dot-done' => $i < $step,
                                    'ph-tour-dot-current' => $i === $step,
                                ])
                            ></span>
                        @endfor
                    </div>

                    <span>{{ trans('plugged-host-core::strings.tour.progress', ['step' => $step, 'total' => $count]) }}</span>
                </div>

                <div
                    class="ph-tour-step"
                    wire:key="ph-tour-{{ $part }}-{{ $step }}"
                    x-init="show(@js($current['targets']), @js($current['sidebar']))"
                >
                    <h2 id="ph-tour-heading" class="ph-tour-heading">{{ $current['heading'] }}</h2>

                    <p id="ph-tour-body" class="ph-tour-body">{{ $current['body'] }}</p>

                    @if ($current['bullets'] !== [])
                        <ul class="ph-tour-bullets">
                            @foreach ($current['bullets'] as $bullet)
                                <li>{{ $bullet }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Wizard order, as elsewhere: Back and the way out on the left, the one primary
                     action on the right. Step 1 of the first part has only its primary button:
                     it is the acknowledgement RequirePanelTour waits for. --}}
                <div class="ph-tour-actions">
                    <div class="ph-tour-actions-start">
                        @if ($step > 1)
                            <x-filament::button wire:click="back" color="gray">
                                {{ trans('plugged-host-core::strings.tour.back') }}
                            </x-filament::button>
                        @endif

                        @if ($current['final'] === 'open' || $current['final'] === 'create')
                            <x-filament::link tag="button" wire:click="finish" color="gray" size="sm">
                                {{ trans('plugged-host-core::strings.tour.done_here') }}
                            </x-filament::link>
                        @elseif ($current['final'] === null && ($part === 'server' || $step > 1))
                            <x-filament::link tag="button" wire:click="skip" color="gray" size="sm">
                                {{ trans('plugged-host-core::strings.tour.skip') }}
                            </x-filament::link>
                        @endif
                    </div>

                    <div class="ph-tour-actions-end">
                        @switch ($current['final'])
                            @case ('open')
                                <x-filament::button wire:click="openServer" x-ref="primary">
                                    {{ trans('plugged-host-core::strings.tour.open_server', ['name' => $server['name'] ?? '']) }}
                                </x-filament::button>
                                @break

                            @case ('create')
                                <x-filament::button wire:click="createServer" x-ref="primary">
                                    {{ trans('plugged-host-core::strings.tour.create_server') }}
                                </x-filament::button>
                                @break

                            @case ('finish')
                                <x-filament::button wire:click="finish" x-ref="primary">
                                    {{ trans('plugged-host-core::strings.tour.finish') }}
                                </x-filament::button>
                                @break

                            @default
                                <x-filament::button wire:click="next" x-ref="primary">
                                    {{ $step === 1 && $part === 'panel' ? trans('plugged-host-core::strings.tour.acknowledge') : trans('plugged-host-core::strings.tour.next') }}
                                </x-filament::button>
                        @endswitch
                    </div>
                </div>
            </section>
        </div>
    @endif
</div>
