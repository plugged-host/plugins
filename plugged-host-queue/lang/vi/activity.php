<?php

// Injected into the '*' namespace via Lang::addLines() (WP7, fact 13) so these render as
// sentences in the activity log. Keys are prefixed 'activity.' to match ActivityLog::getLabel().
return [
    'activity.server.queue.enqueue' => 'đã vào hàng chờ khởi động cho :server',
    'activity.server.queue.release' => 'đã được cho ra khỏi hàng chờ khởi động và :server đã khởi động',
    'activity.server.queue.timeout' => 'đã chạm giới hạn thời gian chờ của hàng chờ khởi động và :server đã khởi động',
];
