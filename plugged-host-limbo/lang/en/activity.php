<?php

// Injected into the '*' namespace via Lang::addLines() in LimboPluginProvider so these custom
// events render as sentences in the activity log instead of raw dotted keys (fact 13). Keys are
// prefixed 'activity.' to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.limbo.stop' => 'stopped :server for being idle',
    'activity.server.limbo.hibernate' => 'moved :server into hibernation',
    'activity.server.limbo.restore' => 'restored :server from hibernation for :cost tokens',
    'activity.server.limbo.deleted' => 'deleted server :name after its time in hibernation ran out',
];
