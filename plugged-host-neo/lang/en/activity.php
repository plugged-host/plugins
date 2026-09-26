<?php

// Injected with Lang::addLines() by NeoPluginProvider so neo's actions read as sentences in the
// server activity log. Keys are prefixed 'activity.' to match ActivityLog::getLabel().
return [
    'activity.server.neo.command' => 'neo executed "<b>:command</b>" on the server',
    'activity.server.neo.power' => 'neo sent the <b>:action</b> power action',
    'activity.server.neo.file.read' => 'neo viewed the contents of <b>:file</b>',
    'activity.server.neo.file.write' => 'neo wrote new content to <b>:file</b>',
    'activity.server.neo.file.edit' => 'neo edited <b>:file</b>',
    'activity.server.neo.file.create-directory' => 'neo created the folder <b>:file</b>',
    'activity.server.neo.file.rename' => 'neo moved <b>:file</b> to <b>:to</b>',
    'activity.server.neo.file.delete' => 'neo deleted <b>:files</b>',
    'activity.server.neo.file.compress' => 'neo created the archive <b>:file</b>',
    'activity.server.neo.file.decompress' => 'neo extracted <b>:file</b>',
    'activity.server.neo.startup.variable' => 'neo set <b>:variable</b> to <b>:new</b>',
    'activity.server.neo.startup.image' => 'neo changed the docker image from <b>:old</b> to <b>:new</b>',
    'activity.server.neo.reinstall' => 'neo reinstalled the server',
    'activity.server.neo.backup.create' => 'neo started the backup <b>:name</b>',
    'activity.server.neo.backup.restore' => 'neo restored the backup <b>:name</b>',
    'activity.server.neo.backup.delete' => 'neo deleted the backup <b>:name</b>',
];
