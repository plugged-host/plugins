{{--
    The wizard needs a real <form>: Wizard's submit slot renders a `type="submit"` button, and
    without a wire:submit around it that button posts nothing. Filament's own installer page is
    built the same way — this is the standard shape, not a workaround.

    x-filament-panels::page (not page.simple) so the server panel's sidebar and tenant menu stay
    where they are: this is one page of a panel, not a takeover. There is nothing in the header —
    setup is not skippable any more, see ServerOnboardingPage::content().

    $this->content is the Page's native schema, the same thing the default page view renders — the
    only difference here is the <form> wrapped around it.
--}}
<x-filament-panels::page>
    <form wire:submit="complete">
        {{ $this->content }}
    </form>
</x-filament-panels::page>
