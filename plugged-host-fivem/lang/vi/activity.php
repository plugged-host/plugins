<?php

// Injected into the '*' namespace via Lang::addLines() (FiveMPluginProvider). Keys prefixed
// 'activity.' to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.fivem.framework' => 'đã cài đặt <b>:recipe</b> vào :folder',
    'activity.server.fivem.artifact' => 'đã chọn bản build FiveM <b>:build</b>',
    'activity.server.fivem.updates' => 'đã đặt cập nhật FiveM thành <b>:version</b>',
    'activity.server.fivem.edition' => 'đã chuyển phiên bản FiveM sang <b>:edition</b>',
];
