<?php

// Injected into the '*' namespace via Lang::addLines() (WP7, fact 13) so these render as
// sentences in the activity log. Keys are prefixed 'activity.' to match ActivityLog::getLabel().
return [
    'activity.server.queue.enqueue' => 'entered the start queue for :server',
    'activity.server.queue.release' => 'was released from the start queue and :server started',
    'activity.server.queue.timeout' => 'reached the start-queue wait limit and :server started',
];
