<?php

/*
|--------------------------------------------------------------------------
| plugged-host-theme-v2
|--------------------------------------------------------------------------
|
| Theme v2 is the only theme (WP2, v1.2.0). Its one setting is the accent
| colour (Admin › Plugins › Theme v2 › Settings): a Tailwind preset name or
| a #rrggbb colour. Blue is theme.css's own; see Support\Accent.
|
*/

return [
    'accent' => env('THEME_V2_ACCENT', 'blue'),
];
