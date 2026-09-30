{{--
    Host for the balance Livewire component.

    The indirection exists because a render hook has to hand back a rendered Htmlable — the
    provider calls ->render() inside its try/catch, since returning an unrendered View would
    defer Blade past the catch and a throw there takes the whole panel down with it. Rendering
    this one-line view is what turns @livewire into markup at a point where a failure is still
    catchable.

    No `key:` argument, deliberately: passing one makes Livewire treat the component as a
    keyed child of a parent component it has no parent for, and it stops mounting.
--}}
@livewire('plugged-host-theme-v2.balance')
