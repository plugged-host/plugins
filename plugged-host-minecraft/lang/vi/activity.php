<?php

// Injected into the '*' namespace via Lang::addLines() (WP7, fact 13). Keys prefixed 'activity.'
// to match ActivityLog::getLabel()'s trans('activity.'.event) lookup.
return [
    'activity.server.minecraft.geyser.install' => 'đã cài đặt Geyser và Floodgate trên :server',
    'activity.server.minecraft.modpack.install' => 'đã cài đặt một modpack trên :server',
    'activity.server.neo.content.install' => 'neo đã cài đặt <b>:file</b>',
    'activity.server.neo.content.remove' => 'neo đã gỡ cài đặt <b>:files</b>',
    'activity.server.neo.content.toggle' => 'neo đã đổi tên một plugin/mod thành <b>:file</b>',
    'activity.server.neo.software' => 'neo đã chuyển máy chủ từ <b>:from</b> sang <b>:to</b> (cài đặt lại)',
    'activity.server.neo.modpack' => 'neo đã bắt đầu cài đặt một modpack (<b>:project</b>)',
    'activity.server.neo.geyser' => 'neo đã cài đặt Geyser và Floodgate',
    'activity.server.minecraft.world.recreate' => 'đã bắt đầu một thế giới mới <b>:world</b>',
    'activity.server.minecraft.world.import' => 'đã tải lên một thế giới với tên <b>:world</b>',
    'activity.server.minecraft.world.activate' => 'đã chuyển sang thế giới <b>:world</b>',
    'activity.server.minecraft.world.delete' => 'đã xóa thế giới <b>:world</b>',
];
