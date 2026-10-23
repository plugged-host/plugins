<?php

// Injected with Lang::addLines() by NeoToolsProvider. Keys prefixed 'activity.' to match
// ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.user.neo.server.create' => 'neo đã tạo máy chủ <b>:name</b>',
    'activity.user.neo.server.delete' => 'neo đã xóa máy chủ <b>:name</b>',
    'activity.user.neo.locations.unlock' => 'neo đã mở khóa vị trí Plus/Premium với <b>:cost</b> token',
    'activity.server.neo.resize' => 'neo đã thay đổi tài nguyên máy chủ từ <b>:from</b> thành <b>:to</b>',
    'activity.server.neo.rename' => 'neo đã đổi tên máy chủ từ <b>:old</b> thành <b>:new</b>',
    'activity.server.transfer.start' => 'Đã bắt đầu chuyển máy chủ đến <b>:location</b>',
    'activity.server.transfer.completed' => 'Đã chuyển máy chủ đến <b>:location</b>',
    'activity.server.transfer.failed' => 'Chuyển máy chủ thất bại (:reason), máy chủ vẫn ở chỗ cũ',
    'activity.server.transfer.cancelled' => 'Đã hủy chuyển máy chủ',
    'activity.server.transfer.stuck' => 'Việc chuyển máy chủ cần quản trị viên xử lý (:reason)',
];
