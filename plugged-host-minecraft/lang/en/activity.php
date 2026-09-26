<?php

// Injected into the '*' namespace via Lang::addLines() (WP7, fact 13). Keys prefixed 'activity.'
// to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.minecraft.geyser.install' => 'installed Geyser and Floodgate on :server',
    'activity.server.minecraft.modpack.install' => 'installed a modpack on :server',
    'activity.server.neo.content.install' => 'neo installed <b>:file</b>',
    'activity.server.neo.content.remove' => 'neo uninstalled <b>:files</b>',
    'activity.server.neo.content.toggle' => 'neo renamed a plugin/mod to <b>:file</b>',
    'activity.server.neo.software' => 'neo switched the server from <b>:from</b> to <b>:to</b> (reinstall)',
    'activity.server.neo.modpack' => 'neo started a modpack install (<b>:project</b>)',
    'activity.server.neo.geyser' => 'neo installed Geyser and Floodgate',
];
