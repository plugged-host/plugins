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
];
