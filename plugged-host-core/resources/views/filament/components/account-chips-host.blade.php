{{--
    Host for the topbar chips.

    A one-line wrapper so the render hook in CoreRuntimeServiceProvider can return a view rather
    than a Blade::render() of a directive string. @livewire() is what actually matters here: the
    chips must be a live component, because the container Filament drops them into is x-persist'd
    and static markup in there never updates again. See PluggedHost\Core\Livewire\AccountChips.
--}}
@livewire('plugged-host-account-chips')
