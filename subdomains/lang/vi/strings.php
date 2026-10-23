<?php

return [
    'no_domains' => 'Không có tên miền',
    'domain' => 'Tên miền|Tên miền',

    'no_subdomains' => 'Không có tên miền phụ',
    'subdomain' => 'Tên miền phụ|Tên miền phụ',
    'limit' => 'Giới hạn',
    'change_limit' => 'Thay đổi giới hạn',
    'limit_changed' => 'Đã thay đổi giới hạn',
    'limit_reached' => 'Đã đạt giới hạn tên miền phụ',
    'create_subdomain' => 'Tạo tên miền phụ',

    'name' => 'Tên',
    'record_type' => 'Loại bản ghi',
    'is_synced' => 'Đã đồng bộ?',
    'srv_target' => 'Đích SRV',
    'no_srv_target' => 'Không có đích SRV',

    'sync' => 'Đồng bộ',

    'api_token' => 'Token API Cloudflare',
    'api_token_help' => 'Token cần có quyền đọc cho Zone.Zone và quyền ghi cho Zone.Dns. Để bảo mật hơn, bạn cũng có thể đặt "Zone Resources" để loại trừ một số tên miền và thêm IP của bảng điều khiển vào "Client IP Address Filtering".',
    'api_token_set_help' => 'Token API Cloudflare hiện đã được đặt. Để trống để giữ nguyên, hoặc nhập token mới để thay thế.',
    'api_token_unset_help' => 'Chưa có token API Cloudflare nào được đặt. Nhập một token để bật đồng bộ DNS với Cloudflare.',
    'secret_unchanged' => '(không đổi)',
    'secret_unset' => 'Chưa đặt',

    'srv_service_section' => 'Nhận diện dịch vụ SRV',
    'srv_service_section_help' => 'Gán ID egg với loại dịch vụ để các bản ghi SRV phù hợp (ví dụ Minecraft hoặc FiveM) được tạo tự động.',
    'minecraft_eggs' => 'ID egg Minecraft',
    'minecraft_eggs_help' => 'Danh sách ID egg, phân tách bằng dấu phẩy, được coi là máy chủ Minecraft khi tạo bản ghi SRV. Để trống: các egg có tag "minecraft" (không gồm Bedrock hay proxy).',
    'fivem_eggs' => 'ID egg FiveM',
    'fivem_eggs_help' => 'Danh sách ID egg, phân tách bằng dấu phẩy, được coi là máy chủ FiveM khi tạo bản ghi SRV. Để trống: các egg có tag "cfx".',

    'subdomain_blacklist' => 'Danh sách chặn tên miền phụ',
    'subdomain_blacklist_help' => "Người dùng sẽ không thể tạo tên miền phụ với các mục này. Hỗ trợ mẫu có '*', ví dụ 'test*' sẽ chặn mọi tên miền phụ bắt đầu bằng 'test'.",

    'notifications' => [
        'synced' => 'Đã đồng bộ tên miền với Cloudflare',
        'not_synced' => 'Không thể đồng bộ tên miền với Cloudflare',
    ],

    'validation' => [
        'on_blacklist' => 'Giá trị của :attribute không được phép.',
    ],
];
