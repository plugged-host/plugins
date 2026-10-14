<?php

// Injected into the '*' namespace via Lang::addLines() (FiveMPluginProvider). Keys prefixed
// 'activity.' to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.fivem.framework' => 'hat <b>:recipe</b> in :folder installiert',
    'activity.server.fivem.artifact' => 'hat FiveM-Build <b>:build</b> gewählt',
    'activity.server.fivem.updates' => 'hat FiveM-Updates auf <b>:version</b> gestellt',
    'activity.server.fivem.edition' => 'hat die FiveM-Edition auf <b>:edition</b> umgestellt',
];
