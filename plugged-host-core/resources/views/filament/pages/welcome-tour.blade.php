{{--
    Never actually shown: WelcomeTourPage::mount() always redirects to the server list, where
    the tour itself (PanelTourOverlay) is drawn. A Filament page still needs a view to exist.
--}}
<x-filament-panels::page />
