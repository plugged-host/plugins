<?php

// Injected with Lang::addLines() by NeoToolsProvider. Keys prefixed 'activity.' to match
// ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.user.neo.server.create' => 'neo created the server <b>:name</b>',
    'activity.user.neo.server.delete' => 'neo deleted the server <b>:name</b>',
    'activity.user.neo.locations.unlock' => 'neo unlocked Plus/Premium locations for <b>:cost</b> tokens',
    'activity.server.neo.resize' => 'neo resized the server from <b>:from</b> to <b>:to</b>',
    'activity.server.neo.rename' => 'neo renamed the server from <b>:old</b> to <b>:new</b>',
    'activity.server.transfer.start' => 'Started moving the server to <b>:location</b>',
    'activity.server.transfer.completed' => 'Moved the server to <b>:location</b>',
    'activity.server.transfer.failed' => 'Moving the server failed (:reason), it stayed where it was',
    'activity.server.transfer.cancelled' => 'Cancelled moving the server',
    'activity.server.transfer.stuck' => 'Moving the server needs an administrator (:reason)',
];
