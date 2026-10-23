<?php

// Injected into the '*' namespace via Lang::addLines() in LimboPluginProvider so these custom
// events render as sentences in the activity log instead of raw dotted keys (fact 13). Keys are
// prefixed 'activity.' to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.limbo.stop' => 'đã dừng :server vì không hoạt động',
    'activity.server.limbo.hibernate' => 'đã chuyển :server sang trạng thái ngủ đông',
    'activity.server.limbo.restore' => 'đã khôi phục :server khỏi trạng thái ngủ đông với :cost token',
];
