{{--
    Shared perk list. Included by every discovery surface (the server-installing page, the
    dashboard, the profile page) so the copy and the numbers exist once.

    Renders nothing at all when there is nothing left to claim — that is the point of the
    whole thing: PluggedHost\Core\Services\PerkDiscovery returns an empty list for a user who
    has already linked Discord and joined the server, so the card disappears instead of
    nagging forever the way the always-on announcement banner did.

    One section, one full-width row per perk, a hairline between rows. Every button is the
    secondary (gray) style: the list is a set of equal offers, so none of them is *the* primary
    action of whatever page it sits on. The perk's `icon` and `color` are no longer drawn —
    the title already says what the offer is. Row styles live in
    plugins/plugged-host-core/resources/css/plugged-host.css (`.ph-rows`, `.ph-row`).

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

        <div class="ph-rows">
            @foreach ($perks as $perk)
                <div class="ph-row">
                    <div class="ph-row-text">
                        <h4 class="ph-row-title">
                            {{ $perk['title'] }}
                        </h4>
                        <p class="ph-row-body">
                            {{ $perk['body'] }}
                        </p>
                    </div>

                    <div class="ph-row-actions">
                        <x-filament::button
                            tag="a"
                            :href="$perk['url']"
                            color="gray"
                            :target="$perk['external'] ? '_blank' : null"
                            :rel="$perk['external'] ? 'noopener noreferrer' : null"
                            size="sm"
                        >
                            {{ $perk['cta'] }}
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
@endif
