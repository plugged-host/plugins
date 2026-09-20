{{--
    Shared perk list. Included by every discovery surface (the server-installing page, the
    dashboard, the profile page) so the copy and the numbers exist once.

    Renders nothing at all when there is nothing left to claim — that is the point of the
    whole thing: PluggedHost\Core\Services\PerkDiscovery returns an empty list for a user who
    has already linked Discord and joined the server, so the card disappears instead of
    nagging forever the way the always-on announcement banner did.

    Colour classes are matched to literals rather than interpolated: Tailwind only compiles
    class names it can see in the source, so `text-{{ $color }}-600` would silently produce
    unstyled markup. Utilities here are compiled by plugins/plugged-host-core/resources/css/
    plugged-host.css; run `yarn build` after introducing a new class.

    Optional params, all defaulting to the original always-open card so the surfaces that want a
    real card are unaffected:
      $heading / $description  — override the copy
      $collapsible             — draw the disclosure chevron
      $collapsed               — start closed (only meaningful with $collapsible)
      $collapseId              — stable key for remembering the open/closed choice per user
      $compact                 — drop the description line entirely, for a one-line bar
--}}
@if (!empty($perks))
    <x-filament::section
        class="rounded-2xl"
        :collapsible="$collapsible ?? false"
        :collapsed="$collapsed ?? false"
        :persist-collapsed="($collapsible ?? false) && filled($collapseId ?? null)"
        :collapse-id="$collapseId ?? null"
    >
        <x-slot name="heading">
            {{ $heading ?? trans('plugged-host-core::strings.perks.heading') }}
        </x-slot>

        @unless ($compact ?? false)
            <x-slot name="description">
                {{ $description ?? trans('plugged-host-core::strings.perks.description') }}
            </x-slot>
        @endunless

        <div class="flex flex-col divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($perks as $perk)
                @php
                    $glyph = match ($perk['color']) {
                        'info' => 'text-info-600 dark:text-info-400',
                        'success' => 'text-success-600 dark:text-success-400',
                        'warning' => 'text-warning-600 dark:text-warning-400',
                        default => 'text-primary-600 dark:text-primary-400',
                    };
                @endphp

                <div class="flex flex-col gap-4 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <x-filament::icon :icon="$perk['icon']" @class(['size-5', $glyph]) />
                        </div>

                        <div class="flex min-w-0 flex-col gap-1">
                            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $perk['title'] }}
                            </h4>
                            <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ $perk['body'] }}
                            </p>
                        </div>
                    </div>

                    <div class="shrink-0 sm:pl-4">
                        <x-filament::button
                            tag="a"
                            :href="$perk['url']"
                            :color="$perk['color']"
                            :target="$perk['external'] ? '_blank' : null"
                            :rel="$perk['external'] ? 'noopener noreferrer' : null"
                            :icon="$perk['external'] ? 'tabler-external-link' : 'tabler-arrow-right'"
                            icon-position="after"
                            size="sm"
                            outlined
                        >
                            {{ $perk['cta'] }}
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
@endif
