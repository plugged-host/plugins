{{--
    The accent colour from the Theme v2 settings, for pages that do not load the panel theme but
    declare its --phv2-accent* tokens themselves (the standalone auth and error pages, the backup
    portal). Include it after their own token block. Prints nothing for the default blue.
--}}
@php($accentCss = \PluggedHost\ThemeV2\Support\Accent::css())
@if ($accentCss !== '')
    <style>{!! $accentCss !!}</style>
@endif
