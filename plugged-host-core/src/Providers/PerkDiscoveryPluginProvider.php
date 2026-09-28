<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Services\PerkDiscovery;

/**
 * Wires up perk discovery, which exists to replace an announcement banner that was pinned
 * to every page whether or not it still applied to the reader.
 *
 * The rule for every surface is the same: show it where the user is already thinking about
 * resources, and let it vanish permanently once the perk is claimed.
 *
 * There is deliberately no surface on the server list any more. PerkDiscoveryWidget used to
 * be registered there, above the server table, and it was the first thing anyone saw on
 * logging in — an "unlock more resources" bar between the reader and the servers they came
 * for. The two remaining surfaces are ones the reader chose to be on: the resource store in
 * plugged-host-economy, and the server-installing page, which is dead time by definition.
 *
 * The widget class stays; only this registration is gone.
 */
class PerkDiscoveryPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton because PerkDiscovery memoises per user: a widget's canView() and its
        // getViewData() both ask for the same list, and so does every other surface on the
        // page. Without this each call would re-run the tier and Discord lookups.
        $this->app->singleton(PerkDiscovery::class);
    }
}
