<?php

// Injected with Lang::addLines() by NeoPluginProvider so neo's actions read as sentences in the
// server activity log. Keys are prefixed 'activity.' to match ActivityLog::getLabel().
return [
    'activity.server.neo.command' => 'neo đã chạy lệnh "<b>:command</b>" trên máy chủ',
    'activity.server.neo.power' => 'neo đã gửi thao tác nguồn <b>:action</b>',
    'activity.server.neo.file.read' => 'neo đã xem nội dung của <b>:file</b>',
    'activity.server.neo.file.write' => 'neo đã ghi nội dung mới vào <b>:file</b>',
    'activity.server.neo.file.edit' => 'neo đã chỉnh sửa <b>:file</b>',
    'activity.server.neo.file.create-directory' => 'neo đã tạo thư mục <b>:file</b>',
    'activity.server.neo.file.rename' => 'neo đã chuyển <b>:file</b> thành <b>:to</b>',
    'activity.server.neo.file.delete' => 'neo đã xóa <b>:files</b>',
    'activity.server.neo.file.compress' => 'neo đã tạo tệp nén <b>:file</b>',
    'activity.server.neo.file.decompress' => 'neo đã giải nén <b>:file</b>',
    'activity.server.neo.startup.variable' => 'neo đã đặt <b>:variable</b> thành <b>:new</b>',
    'activity.server.neo.startup.image' => 'neo đã đổi docker image từ <b>:old</b> sang <b>:new</b>',
    'activity.server.neo.reinstall' => 'neo đã cài đặt lại máy chủ',
    'activity.server.neo.backup.create' => 'neo đã bắt đầu tạo bản sao lưu <b>:name</b>',
    'activity.server.neo.backup.restore' => 'neo đã khôi phục bản sao lưu <b>:name</b>',
    'activity.server.neo.backup.delete' => 'neo đã xóa bản sao lưu <b>:name</b>',
];
