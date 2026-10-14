<?php

// Injected into the '*' namespace via Lang::addLines() (FiveMPluginProvider). Keys prefixed
// 'activity.' to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.fivem.framework' => 'installed <b>:recipe</b> into :folder',
    'activity.server.fivem.artifact' => 'chose FiveM build <b>:build</b>',
    'activity.server.fivem.updates' => 'set FiveM updates to <b>:version</b>',
    'activity.server.fivem.edition' => 'switched the FiveM edition to <b>:edition</b>',
];
