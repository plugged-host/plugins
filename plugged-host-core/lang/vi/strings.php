<?php

return [
    // The server sidebar's collapsed group (PluggedHost\Core\Filament\ServerNavigation).
    'nav' => [
        'more' => 'Thêm',
    ],

    // A node with backups turned off (Support\NodeBackups, NodeAwareInitiateBackupService).
    'backups' => [
        'disabled' => 'Sao lưu đã bị tắt cho các máy chủ trên node này.',
    ],

    // The node daemon (Wings) did not answer a request (DaemonResiliencePluginProvider).
    'daemon' => [
        'connection_title' => 'Lỗi kết nối daemon',
        'connection_body' => 'Không thể kết nối tới daemon của node. Vui lòng thử lại sau.',
    ],

    // Services\AllocationInvariant: every server keeps one primary allocation of its own.
    'allocations' => [
        'primary_required' => 'Mỗi máy chủ phải có một allocation mạng chính.',
        'primary_invalid' => 'Mỗi máy chủ phải có một allocation mạng chính hợp lệ.',
        'primary_taken' => 'Allocation mạng chính đã được gán cho một máy chủ khác.',
        'primary_foreign' => 'Allocation mạng chính phải được gán cho máy chủ này.',
        'primary_retain' => 'Một máy chủ phải luôn giữ một allocation mạng chính.',
    ],

    'role' => 'Vai trò|Vai trò',
    'user' => 'Người dùng|Người dùng',
    'cpu' => 'CPU',
    'memory' => 'Bộ nhớ',
    'disk' => 'Dung lượng ổ đĩa',
    'no_limit' => 'Không giới hạn',
    'unlimited' => 'Không giới hạn',
    'hint_unlimited' => '0 nghĩa là không giới hạn',
    'name' => 'Tên máy chủ',
    'egg' => 'Egg',
    'left' => 'còn lại',
    'variables' => 'Biến khởi động',

    'create_server' => 'Tạo máy chủ',
    'create_network_ports' => 'Tạo cổng mạng tiếp theo',

    'choose_game_description' => 'Chọn một trò chơi để bắt đầu. Bạn có thể cấu hình nó ở bước tiếp theo.',
    'no_games' => 'Không có trò chơi nào',
    'no_games_description' => 'Hiện không có trò chơi nào bạn có thể triển khai. Vui lòng liên hệ quản trị viên của bảng điều khiển.',

    'configure_server_description' => 'Chọn cấu hình của bạn, rồi triển khai.',
    'deploy_server' => 'Triển khai máy chủ',

    'choose_type' => 'Phần mềm máy chủ',
    'choose_type_description' => 'Bạn muốn chạy phiên bản máy chủ trò chơi nào?',
    'choose_type_onboarding' => 'Bạn sẽ chọn mục này trong hướng dẫn thiết lập sau khi máy chủ được tạo, cùng với phiên bản Minecraft - để hai thứ luôn khớp nhau. Hướng dẫn sẽ tự mở vào lần đầu bạn mở máy chủ mới.',

    'choose_location' => 'Vị trí',
    'choose_location_description' => 'Máy chủ của bạn nên được đặt ở đâu?',
    'location_unavailable' => 'Không đủ sức chứa cho tài nguyên đã chọn',
    'no_locations' => 'Chưa có vị trí nào được cấu hình. Vui lòng liên hệ quản trị viên của bảng điều khiển.',

    'choose_plan' => 'Tài nguyên',
    'choose_plan_description' => 'Chọn một gói, hoặc chuyển sang chế độ nâng cao để đặt giá trị tùy chỉnh.',
    'plan_unavailable' => 'Vượt quá hạn mức còn lại của bạn',
    'advanced_resources' => 'Nâng cao',
    'advanced_resources_hint' => 'Tự đặt CPU, bộ nhớ và ổ đĩa thay vì dùng gói.',

    'server_details' => 'Chi tiết',
    'server_details_description' => 'Đặt tên cho máy chủ và điều chỉnh các biến khởi động.',
    'eula_agree' => 'Tôi đồng ý với Thỏa thuận cấp phép người dùng cuối (EULA) của Minecraft',
    'eula_description' => 'Khi tạo máy chủ này, bạn đồng ý với EULA của Minecraft (https://aka.ms/MinecraftEULA). EULA sẽ được tự động chấp nhận trên máy chủ của bạn để máy chủ khởi động ngay mà không bị dừng lại.',
    'resize_plan_description' => 'Chọn một gói, hoặc chuyển sang chế độ nâng cao để đặt giá trị tùy chỉnh.',
    'plan_current' => 'Gói hiện tại',

    'change_version_description' => 'Chuyển sang phiên bản Minecraft hoặc phần mềm máy chủ khác.',
    'change_version_section' => 'Phiên bản và phần mềm',
    'change_version_section_description' => 'Chọn phần mềm và phiên bản cần cài đặt. Khi áp dụng, máy chủ sẽ được cài đặt lại.',
    'change_version_apply' => 'Thay đổi và cài đặt lại',
    'change_version_confirm' => 'Thay đổi phiên bản và cài đặt lại?',
    'change_version_warning' => 'Máy chủ sẽ bị dừng và cài đặt lại với phần mềm và phiên bản đã chọn. Thế giới và các tệp cấu hình được giữ lại, nhưng các tệp jar và thư viện mod sẽ bị thay thế. Mod và plugin được làm cho phiên bản cũ có thể ngừng hoạt động.',
    'server_software' => 'Phần mềm máy chủ',
    'minecraft_version' => 'Phiên bản Minecraft',
    'minecraft_version_hint' => 'Chỉ liệt kê các phiên bản có sẵn cho phần mềm đã chọn.',
    'version_latest' => 'Mới nhất (khuyên dùng)',

    'server_settings' => [
        'sections' => [
            'general' => 'Chung',
            'resources' => 'Tài nguyên',
            'startup' => 'Khởi động',
            'startup_command' => 'Lệnh khởi động',
            'version' => 'Phiên bản',
        ],
        'jump_to' => 'Chuyển đến',
        'save_resources' => 'Lưu tài nguyên',
    ],

    'content' => [
        'nav_plugins' => 'Plugin',
        'nav_mods' => 'Mod & modpack',
        'subheading_plugins' => 'Duyệt Modrinth và cài plugin thẳng vào máy chủ này. Chỉ liệt kê các plugin dành cho phần mềm máy chủ và phiên bản Minecraft của bạn.',
        'subheading_mods' => 'Duyệt Modrinth và cài mod, modpack thẳng vào máy chủ này. Chỉ liệt kê nội dung dành cho loader và phiên bản Minecraft của bạn.',
        'search' => 'Tìm trên Modrinth...',
        'col_project' => 'Dự án',
        'col_author' => 'Tác giả',
        'col_downloads' => 'Lượt tải',
        'col_categories' => 'Danh mục',
        'filter_kind' => 'Loại',
        'filter_version' => 'Phiên bản Minecraft',
        'kind_mod' => 'Mod',
        'kind_modpack' => 'Modpack',
        'kind_plugin' => 'Plugin',
        'empty_heading' => 'Không tìm thấy gì',
        'empty_body' => 'Hãy thử từ khóa khác, hoặc bỏ bộ lọc phiên bản Minecraft - không phải tác giả nào cũng ghi mọi phiên bản họ hỗ trợ.',
        'installed_badge' => 'Đã cài đặt',
        'quick_install' => 'Cài đặt 1 cú nhấp',
        'versions' => 'Phiên bản',
        'install' => 'Cài đặt',
        'install_heading' => 'Cài đặt :name',
        'install_submit' => 'Cài đặt',
        'version' => 'Phiên bản',
        'version_loose' => 'Không có bản build nào ghi Minecraft :version, nên tất cả bản build đều được hiển thị. Hãy chọn bản mới nhất và thử - phần lớn vẫn chạy được.',
        'version_gone' => 'Phiên bản đó không còn trên Modrinth.',
        'view' => 'Xem trên Modrinth',
        'modpack_warning' => 'Máy chủ sẽ được chuyển sang loader và phiên bản Minecraft mà modpack này cần, được cài đặt lại, rồi các tệp của modpack mới được đặt vào. Thế giới của bạn được giữ lại. Không thể cố định chính xác bản build loader của modpack, nên modpack đòi một bản build cụ thể vẫn có thể cần bạn xử lý thủ công.',
        'client_only_warning' => 'Tác giả đánh dấu dự án này chỉ dành cho phía client, nên nó có thể không có tác dụng gì trên máy chủ.',
        'modpack_queued' => 'Đã bắt đầu cài đặt modpack',
        'modpack_queued_body' => ':name đang được cài đặt. Máy chủ được cài đặt lại trước, sau đó các tệp của modpack mới được đặt vào - việc này mất vài phút. Bạn sẽ nhận được thông báo khi hoàn tất.',
        'no_file' => 'Phiên bản đó không có tệp nào để tải xuống.',
        'pull_failed' => 'Máy chủ không thể tải tệp xuống. Vui lòng thử lại.',
        'installed' => 'Đã bắt đầu cài đặt',
        'installed_body' => ':file đang được tải vào :directory/. Hãy khởi động lại máy chủ để nạp nó.',
        'modpack_done' => 'Đã cài đặt modpack',
        'modpack_done_body' => ':name đã được cài đặt và :count tệp đã được tải xuống máy chủ. Hãy khởi động máy chủ và theo dõi cửa sổ lệnh.',
        'modpack_failed' => 'Cài đặt modpack thất bại',
        'modpack_failed_body' => 'Không thể cài đặt :name. Hãy kiểm tra cửa sổ lệnh và danh sách tệp trước khi khởi động máy chủ.',
        'modpack_reinstall_failed' => 'Không thể cài đặt lại máy chủ cho modpack này, nên không có tệp nào của modpack được đặt vào.',
        'modpack_broken' => 'Không thể đọc tệp modpack đó. Có thể nó dùng định dạng mà bảng điều khiển này không hỗ trợ.',
        'modpack_unsupported' => 'Modpack đó cần một loader mà máy chủ này không thể cài đặt.',
        'modpack_download_failed' => 'Không thể tải modpack từ Modrinth. Vui lòng thử lại.',
        'modpack_overrides_skipped' => 'Các tệp cấu hình đi kèm modpack quá lớn để áp dụng tự động - hãy tải modpack từ Modrinth và tải thư mục overrides của nó lên qua trình quản lý tệp.',
    ],

    // The typed editor for the config files a Minecraft server keeps in its own data
    // directory. Which files show up depends on the server software and on what it has
    // actually written, so a file being absent is ordinary rather than an error.
    'game_config' => [
        'nav_label' => 'Cài đặt trò chơi',
        'title' => 'Cài đặt trò chơi',
        'subheading' => 'Chỉnh sửa các tệp cấu hình trên máy chủ này. Giá trị đã lưu có hiệu lực vào lần khởi động tiếp theo.',
        'empty_heading' => 'Chưa có tệp cấu hình nào',
        'empty_body' => 'Máy chủ chưa có tệp nào mà trang này có thể chỉnh sửa. Hãy khởi động máy chủ một lần để nó tạo các tệp đó, rồi quay lại đây.',
        'unreachable' => 'Không thể kết nối tới máy chủ, nên không đọc được các tệp cấu hình. Vui lòng thử lại sau giây lát.',
        'general' => 'Chung',
        'properties_notice' => 'Chú thích, thứ tự khóa và mọi khóa không hiển thị ở đây đều được giữ nguyên. Cổng và mật khẩu RCON do bảng điều khiển quản lý và không thể chỉnh sửa.',
        'yaml_notice' => 'Khi lưu tệp này, các chú thích trong đó sẽ bị bỏ - bản gốc được giữ lại dưới tên :file. Các danh sách và mục mà biểu mẫu này không hiển thị vẫn được giữ nguyên.',
        'save' => 'Lưu',
        'raw_edit' => 'Sửa dạng văn bản',
        'raw_description' => 'Ghi tệp đúng như bạn để ở đây. Không có gì được kiểm tra, nên một lỗi nhỏ cũng có thể khiến máy chủ không khởi động được.',
        'saved' => 'Đã lưu :file',
        'saved_body' => 'Hãy khởi động lại máy chủ để áp dụng các giá trị mới.',
        'no_changes' => 'Không có gì để lưu',
        'no_changes_body' => 'Tệp này đã chứa đúng các giá trị đang hiển thị.',
        'failed' => 'Đã xảy ra lỗi',
        'read_failed' => 'Không thể đọc tệp từ máy chủ. Vui lòng thử lại.',
        'save_failed' => 'Không thể ghi tệp lên máy chủ, nên không có gì thay đổi.',
        'backup_failed' => 'Không thể lưu bản sao của tệp gốc dưới tên :file, nên tệp được giữ nguyên.',
        'invalid_number' => ':key phải là một số.',
        'invalid_choice' => '":value" không phải là giá trị hợp lệ cho :key.',
        'restart' => 'Khởi động lại máy chủ',
        'restart_confirm' => 'Khởi động lại máy chủ ngay bây giờ?',
        'restart_warning' => 'Máy chủ sẽ dừng rồi khởi động lại để đọc cấu hình đã lưu. Mọi người đang chơi sẽ bị ngắt kết nối trong lúc khởi động lại.',
        'restarting' => 'Máy chủ đang khởi động lại',
        'restart_failed' => 'Không thể khởi động lại máy chủ. Hãy thử lại từ cửa sổ lệnh.',
    ],

    // Whitelist and operators. A change reaches a running server through its console and a
    // stopped one through its files, and the wording has to keep that difference visible:
    // a file edit is only read on the next start.
    'players' => [
        'nav_label' => 'Người chơi',
        'subheading' => 'Chọn ai được phép vào máy chủ này và ai có quyền OP.',
        'unreachable_heading' => 'Không thể tải danh sách người chơi',
        'unreachable' => 'Không thể kết nối tới máy chủ, nên không đọc được danh sách trắng và danh sách OP. Vui lòng thử lại sau giây lát.',
        'enforcement' => 'Danh sách trắng',
        'enforcement_description' => 'Khi danh sách trắng đang bật, chỉ những người chơi có tên bên dưới mới vào được.',
        'enforced' => 'Chỉ cho phép người chơi trong danh sách trắng',
        'offline_mode' => 'Máy chủ này không kiểm tra tài khoản với Mojang, nên ai cũng có thể chơi dưới bất kỳ tên nào. Danh sách trắng chỉ so khớp theo tên.',
        'col_player' => 'Người chơi',
        'col_whitelisted' => 'Trong danh sách trắng',
        'col_operator' => 'OP',
        'op_level' => 'Cấp :level',
        'search' => 'Tìm người chơi',
        'empty_heading' => 'Chưa có người chơi nào',
        'empty_body' => 'Chưa có ai trong danh sách trắng hoặc có quyền OP trên máy chủ này. Hãy thêm một người chơi để bắt đầu.',
        'whitelist' => 'Thêm vào danh sách trắng',
        'unwhitelist' => 'Xóa khỏi danh sách trắng',
        'unwhitelist_confirm' => 'Xóa :name khỏi danh sách trắng?',
        'unwhitelist_warning' => 'Người chơi này sẽ không thể vào nữa, và sẽ bị ngắt kết nối ngay nếu đang chơi.',
        'op' => 'Cấp quyền OP',
        'deop' => 'Gỡ quyền OP',
        'op_confirm' => 'Cấp quyền OP cho :name?',
        'deop_confirm' => 'Gỡ quyền OP của :name?',
        'op_warning' => 'OP có thể chạy lệnh, thay đổi trò chơi và chỉnh sửa thế giới. Chỉ cấp quyền này cho người bạn tin tưởng.',
        'deop_warning' => 'Người chơi này vẫn vào được máy chủ nhưng mất mọi lệnh OP.',
        'add' => 'Thêm người chơi',
        'username' => 'Tên người dùng Minecraft',
        'username_hint' => 'Tên phải là một tài khoản Minecraft có thật.',
        'username_hint_offline' => 'Máy chủ này không kiểm tra tài khoản, nên tên được dùng đúng như bạn nhập.',
        'access' => 'Cấp cho họ',
        'access_whitelist' => 'Quyền vào máy chủ',
        'access_operator' => 'Quyền OP',
        'access_both' => 'Quyền vào máy chủ và quyền OP',
        'refresh' => 'Tải lại',
        'whitelisted' => 'Đã thêm :name vào danh sách trắng',
        'unwhitelisted' => 'Đã xóa :name khỏi danh sách trắng',
        'opped' => ':name giờ đã có quyền OP',
        'deopped' => ':name không còn quyền OP',
        'enforced_on' => 'Đã bật danh sách trắng',
        'enforced_off' => 'Đã tắt danh sách trắng',
        'restart_note' => 'Đã lưu vào tệp của máy chủ - thay đổi có hiệu lực vào lần khởi động tiếp theo.',
        'failed' => 'Đã xảy ra lỗi',
        'unknown_user' => 'Không có tài khoản Minecraft nào tên ":name", nên không có gì thay đổi.',
        'write_failed' => 'Không thể ghi :file lên máy chủ, nên không có gì thay đổi.',
        'properties_missing' => 'Không thể đọc server.properties, nên cài đặt danh sách trắng được giữ nguyên. Hãy khởi động máy chủ một lần để nó tạo tệp này.',
    ],

    'plans' => [
        'starter' => 'Starter',
        'standard' => 'Standard',
        'pro' => 'Pro',
        'ultimate' => 'Ultimate',
        'extreme' => 'Extreme',
        'master' => 'Master',
    ],

    'game_types' => [
        'purpur' => [
            'label' => 'Vanilla / Paper / Spigot / Purpur',
            'description' => 'Máy chủ tiêu chuẩn. Hỗ trợ plugin Bukkit, Spigot và Paper.',
        ],
        'fabric' => [
            'label' => 'Fabric',
            'description' => 'Loader mod gọn nhẹ. Dùng cho mod Fabric.',
        ],
        'neoforge' => [
            'label' => 'NeoForge',
            'description' => 'Bản fork hiện đại của Forge. Dùng cho mod NeoForge.',
        ],
        'forge' => [
            'label' => 'Forge',
            'description' => 'Loader mod kinh điển. Dùng cho mod Forge và các modpack cũ.',
        ],
    ],

    'validation' => [
        'allocation_limit' => 'Bạn có thể thêm :count cổng nữa cho máy chủ này.',
        'no_primary_allocation' => 'Máy chủ này không có allocation mạng chính.',
        'no_available_port' => 'Không tìm thấy cổng trống nào sau cổng chính hiện tại.',
    ],

    'danger_zone' => 'Vùng nguy hiểm',

    'admin_delete_user' => [
        'label' => 'Xóa người dùng & máy chủ',
        'submit' => 'Xóa người dùng & máy chủ',
        'heading' => 'Xóa :username và các máy chủ của họ',
        'description' => 'Người dùng này sở hữu :count máy chủ: :servers. Xóa tài khoản sẽ hủy các máy chủ đó cùng mọi thứ trên chúng, bao gồm thế giới, tệp, cơ sở dữ liệu và bản sao lưu. Không thể hoàn tác thao tác này.',
        'acknowledge' => 'Có, xóa vĩnh viễn :count máy chủ này cùng mọi thứ trên chúng',
        'confirm_username' => 'Nhập tên người dùng (:username) để xác nhận',
        'force' => 'Xóa kể cả khi node không phản hồi',
        'force_helper' => 'Bảng điều khiển sẽ bỏ từng máy chủ kể cả khi node của nó không thể xác nhận việc xóa. Chỉ dùng tùy chọn này cho node đã mất hẳn: container có thể vẫn còn chạy.',
    ],

    'modals' => [
        'delete_server_confirm' => 'Bạn có chắc chắn muốn xóa máy chủ này?',
        'delete_server_warning' => 'Không thể hoàn tác thao tác này và mọi dữ liệu sẽ bị mất vĩnh viễn.',
        'delete_server' => 'Xóa máy chủ',
    ],

    'notifications' => [
        'server_resources_updated' => 'Đã cập nhật giới hạn tài nguyên máy chủ',
        'might_need_restart' => 'Có thể cần khởi động lại máy chủ để dùng trọn giới hạn tài nguyên mới.',
        'manual_restart_needed' => 'Vui lòng tự khởi động lại máy chủ để áp dụng giới hạn tài nguyên mới.',
        'server_resources_not_updated' => 'Chưa cập nhật giới hạn tài nguyên',

        'server_deleted' => 'Đã xóa máy chủ',
        'server_deleted_success' => 'Máy chủ đã được xóa thành công.',
        'server_delete_error' => 'Không thể xóa máy chủ',

        'change_version_started' => 'Đang cài đặt lại máy chủ',
        'change_version_started_body' => 'Phần mềm và phiên bản đã chọn đang được cài đặt. Việc này có thể mất vài phút.',
        'change_version_failed' => 'Không thể thay đổi phiên bản',
        'invalid_loader' => 'Phần mềm máy chủ đó không có sẵn cho trò chơi này.',
        'unsupported_version' => 'Minecraft :version không có sẵn cho :loader.',

        'account_delete_failed' => 'Không thể xóa tài khoản',
        'account_delete_has_servers' => 'Không thể xóa tài khoản còn máy chủ đang hoạt động. Vui lòng xóa các máy chủ của bạn trước.',
        'account_delete_last_admin' => 'Không thể xóa quản trị viên gốc cuối cùng.',
        'account_delete_server_failed' => 'Không thể xóa máy chủ ":server" (:error), nên tài khoản được giữ nguyên.',
        'account_delete_server_unreachable' => 'node của máy chủ không phản hồi',
        'account_delete_partial' => ':count máy chủ đã được gỡ trước khi lỗi đó xảy ra.',
        'account_delete_servers_not_acknowledged' => 'Vui lòng xác nhận rằng các máy chủ có thể bị xóa.',
        'account_delete_username_mismatch' => 'Không khớp với tên người dùng.',
        'account_delete_unknown_error' => 'Lỗi không xác định. Vui lòng liên hệ quản trị viên của bảng điều khiển.',
        'user_deleted' => 'Đã xóa người dùng',
        'user_deleted_body' => 'Đã xóa :username và :count máy chủ.',

        'server_creation_failed' => 'Không thể tạo máy chủ',
        'no_viable_node_found' => 'Không tìm thấy node phù hợp. Vui lòng liên hệ quản trị viên của bảng điều khiển.',
        'no_viable_allocation_found' => 'Không tìm thấy allocation phù hợp. Vui lòng liên hệ quản trị viên của bảng điều khiển.',
        'unknown_server_creation_error' => 'Lỗi không xác định. Vui lòng liên hệ quản trị viên của bảng điều khiển.',
        'resource_limit_exceeded' => 'Thao tác này sẽ vượt quá giới hạn tài nguyên hoặc giới hạn máy chủ của bạn.',
        'invalid_location' => 'Vui lòng chọn một vị trí hợp lệ.',
        'no_plan_selected' => 'Vui lòng chọn một gói, hoặc chuyển sang chế độ nâng cao và đặt giá trị tùy chỉnh.',

        'ports_created' => 'Đã tạo cổng mạng',
        'ports_created_body' => 'Đã thêm cổng :address.',
        'ports_create_failed' => 'Không thể tạo cổng mạng',
        'ports_conflict' => 'Một hoặc nhiều cổng không còn trống. Vui lòng thử lại.',
        'ports_unknown_error' => 'Không thể tạo các cổng. Vui lòng liên hệ quản trị viên của bảng điều khiển.',

        'primary_allocation_required' => 'Không thể gỡ allocation chính',
        'primary_allocation_required_body' => 'Hãy đặt một allocation mạng khác làm allocation chính trước khi gỡ allocation này.',
        'locked_allocation_required' => 'Không thể gỡ allocation đã khóa',
        'locked_allocation_required_body' => 'Allocation mạng này đã bị khóa và không thể gỡ.',
    ],

    // The waiting page a server sits on until its egg has installed. Deliberately free of
    // any percentage: wings reports no completion fraction, so a progress bar would be
    // invented. Phase, elapsed time and a clear failure state instead.
    'installing' => [
        'nav_label' => 'Đang thiết lập',
        'title' => 'Đang thiết lập máy chủ của bạn',
        'title_reinstall' => 'Đang cài đặt lại máy chủ của bạn',
        'title_failed' => 'Cài đặt thất bại',
        'subheading' => ':server đang được cài đặt. Trang này tự cập nhật và sẽ mở bảng điều khiển ngay khi máy chủ sẵn sàng.',
        'subheading_failed' => 'Quá trình cài đặt chưa hoàn tất. Các tệp của bạn vẫn nguyên vẹn, nhưng máy chủ chưa thể khởi động.',
        'status_body' => 'Hầu hết các lần cài đặt hoàn tất trong vài phút; modpack lớn hơn sẽ lâu hơn. Bạn có thể đóng tab này và quay lại sau.',
        'failed_body' => 'Đã xảy ra lỗi trong lúc cài đặt. Nguyên nhân gần như luôn là tải xuống thất bại hoặc một phiên bản không còn tồn tại — hãy liên hệ với chúng tôi và chúng tôi sẽ xử lý.',
        'elapsed' => 'Đã chạy được',
        'get_help' => 'Nhận trợ giúp',
        'steps_heading' => 'Tiến trình',
        'step_allocated' => 'Đã tạo máy chủ và giữ chỗ tài nguyên',
        'step_installing' => 'Đang tải xuống và cài đặt :egg',
        'step_installing_generic' => 'Đang tải xuống và cài đặt các tệp máy chủ',
        'step_ready' => 'Đang khởi động lần đầu',
        'details_heading' => 'Những gì đang được thiết lập',
        'detail_type' => 'Loại',
        'while_you_wait' => 'Trong lúc chờ',
        'ready_title' => 'Máy chủ của bạn đã sẵn sàng',
        'ready_body' => ':server đã cài đặt xong và có thể khởi động ngay bây giờ.',
    ],

    // Feeds every perk-discovery surface through PluggedHost\Economy\Services\PerkDiscovery.
    // Amounts are substituted at runtime from the noprofit.space tiers and the store prices,
    // so no figure is restated here — change a tier and this copy follows.
    // The welcome tour (PluggedHost\Core\Livewire\PanelTourOverlay, steps in
    // Support\PanelTourSteps). It is drawn over the real panel, so every step sits next to the
    // thing it describes and names it by the label it carries on screen -- the whole point is
    // that someone told "go to Settings" can then find Settings.
    //
    // `panel` is the first part, on the server list; `server` the second, on a server's
    // console. `bullets` is an optional array; keep steps short, a wall of text is the thing
    // people skip.
    'tour' => [
        'title' => 'Chào mừng',
        'progress' => 'Bước :step / :total',
        'acknowledge' => 'Dẫn tôi tham quan',
        'next' => 'Tiếp',
        'back' => 'Quay lại',
        'skip' => 'Bỏ qua chuyến tham quan',
        'finish' => 'Hoàn tất',
        'done_here' => 'Để sau',
        'open_server' => 'Cho tôi xem :name',
        'create_server' => 'Tạo máy chủ đầu tiên của tôi',
        'menu' => 'Xem lại chuyến tham quan chào mừng',
        'panel' => [
            'welcome' => [
                'heading' => 'Chào mừng bạn đến với :app',
                'body' => 'Đây là bảng điều khiển máy chủ trò chơi của bạn: mọi thứ bạn thuê từ chúng tôi đều nằm ở đây. Chúng tôi sẽ làm nổi bật từng phần và cho bạn biết nó dùng để làm gì. Chỉ mất khoảng một phút.',
            ],
            'navigation' => [
                'heading' => 'Thanh bên',
                'body' => 'Mọi trang trong tài khoản của bạn đều chỉ cách một cú nhấp ở đây.',
                // Keyed by sidebar row: PanelTourSteps leaves out the rows that are not there.
                'bullets' => [
                    'home' => 'Trang chủ — các máy chủ của bạn. Bạn đang ở trang này.',
                    'store' => 'Cửa hàng tài nguyên — thêm CPU, bộ nhớ và ổ đĩa bằng credit của bạn.',
                    'plus' => ':app Plus — vị trí tốt hơn, được ưu tiên trong hàng chờ khởi động và không có quảng cáo trong game.',
                    'tokens' => 'Token — kiếm token và xem chúng đã được dùng vào đâu.',
                    'backups' => 'Bản sao lưu — tải xuống bản sao lưu của bất kỳ máy chủ nào của bạn.',
                    'profile' => 'Cài đặt hồ sơ — ngôn ngữ, múi giờ và tài khoản của bạn.',
                ],
            ],
            'balance' => [
                'heading' => 'Mức sử dụng và số dư',
                'body' => 'Các thanh cho thấy các máy chủ của bạn cộng lại đang dùng bao nhiêu trong gói của bạn. Bên dưới là token và credit của bạn.',
                'bullets' => [
                    'Credit dùng để mua thêm tài nguyên trong Cửa hàng tài nguyên.',
                    'Token dùng để mua :app Plus; kiếm token trên trang Token.',
                    'Nhấp vào các thanh hoặc credit để mở Cửa hàng tài nguyên, nhấp vào token để mở trang Token.',
                ],
            ],
            'account' => [
                'heading' => 'Tài khoản của bạn',
                'body' => 'Tên của bạn mở menu tài khoản: hồ sơ, đăng xuất, và chuyến tham quan này nếu bạn muốn xem lại.',
            ],
            'create' => [
                'heading' => 'Tạo máy chủ',
                'body' => 'Nút này tạo một máy chủ mới. Chọn trò chơi, vị trí và kích thước, một phút sau là máy chủ sẵn sàng.',
            ],
            'servers' => [
                'heading' => 'Các máy chủ của bạn',
                'body' => 'Mỗi máy chủ của bạn là một thẻ như thế này. Thanh bên trái cho biết máy chủ có đang chạy không, các con số cho biết mức sử dụng hiện tại. Nhấp vào một thẻ để mở máy chủ đó — cùng mở :name nhé.',
            ],
            'empty' => [
                'heading' => 'Các máy chủ của bạn',
                'body' => 'Các máy chủ của bạn sẽ hiện ở đây, mỗi máy chủ một thẻ, kèm trạng thái đang chạy và mức sử dụng. Bạn chưa có máy chủ nào.',
            ],
            'create_first' => [
                'heading' => 'Tạo máy chủ đầu tiên',
                'body' => 'Bắt đầu từ đây: chọn trò chơi, vị trí và kích thước. Lần đầu bạn mở máy chủ mới, chuyến tham quan sẽ dẫn bạn xem bên trong.',
            ],
        ],
        'server' => [
            'console' => [
                'heading' => 'Cửa sổ lệnh',
                'body' => 'Đầu ra trực tiếp của máy chủ: mọi thứ máy chủ in ra đều hiện ở đây ngay khi xảy ra. Nhập lệnh vào dòng bên dưới và nhấn Enter để chạy.',
            ],
            'power' => [
                'heading' => 'Bật, Khởi động lại, Dừng',
                'body' => 'Các nút nguồn của máy chủ này. Dừng cho phép trò chơi lưu lại và tắt đúng cách; nếu máy chủ bị treo khi đang dừng, nút Dừng sẽ chuyển thành Dừng ngay.',
            ],
            'address' => [
                'heading' => 'Tên, trạng thái và địa chỉ',
                'body' => 'Địa chỉ là thứ người chơi nhập để vào máy chủ. Nhấp vào để sao chép, rồi gửi cho bạn bè. CPU, bộ nhớ và ổ đĩa được cập nhật trực tiếp.',
            ],
            'setup' => [
                'heading' => 'Hoàn tất thiết lập',
                'body' => 'Máy chủ này vẫn còn hướng dẫn thiết lập đang chờ: hướng dẫn sẽ chọn phiên bản Minecraft và phần mềm, rồi chuẩn bị máy chủ sẵn sàng để chơi.',
            ],
            'sidebar' => [
                'heading' => 'Mọi thứ về máy chủ này',
                'body' => 'Khi một máy chủ đang mở, thanh bên dành riêng cho máy chủ đó.',
                'bullets' => [
                    'Tệp — tải lên, chỉnh sửa và xóa mọi thứ trên máy chủ.',
                    'Sao lưu — tạo một bản trước khi bạn thay đổi gì đó, khôi phục nếu có gì hỏng.',
                    'Thêm — các trang bạn ít dùng hơn: tên miền phụ, cổng mạng, cơ sở dữ liệu, lịch, người dùng và nhật ký hoạt động.',
                ],
            ],
            'sidebar_minecraft' => [
                'heading' => 'Mọi thứ về máy chủ này',
                'body' => 'Khi một máy chủ đang mở, thanh bên dành riêng cho máy chủ đó.',
                'bullets' => [
                    'Tệp — tải lên, chỉnh sửa và xóa mọi thứ trên máy chủ.',
                    'Plugin hoặc Mod — cài đặt và cập nhật từ Modrinth, CurseForge và SpigotMC.',
                    'Thế giới và Người chơi — chuyển thế giới, quản lý danh sách trắng, OP và lệnh cấm.',
                    'Cài đặt trò chơi — tệp server.properties dưới dạng biểu mẫu.',
                    'Sao lưu — tạo một bản trước khi bạn thay đổi gì đó, khôi phục nếu có gì hỏng.',
                    'Thêm — các trang bạn ít dùng hơn: tên miền phụ, cổng mạng, cơ sở dữ liệu và nhiều hơn nữa.',
                ],
            ],
            'settings' => [
                'heading' => 'Cài đặt',
                'body' => 'Một trang cho mọi thứ về máy chủ này: đổi tên, thêm bộ nhớ, đổi phiên bản đang chạy, hoặc cài đặt lại.',
            ],
            'home' => [
                'heading' => 'Quay lại các máy chủ của bạn',
                'body' => 'Logo đưa bạn về danh sách máy chủ. Chuyến tham quan đến đây là hết — bạn có thể xem lại bất cứ lúc nào từ menu dưới tên của bạn.',
            ],
        ],
    ],

    // Admin settings form (PluggedHostCorePlugin, over EnvironmentWriterTrait). The sidebar
    // section carries its own inline labels; these keys cover the welcome-tour knobs.
    'settings' => [
        'section_tour' => 'Chuyến tham quan chào mừng',
        'section_tour_hint' => 'Hướng dẫn trong lần đăng nhập đầu tiên mà người dùng mới được dẫn qua. Tắt nó sẽ ngừng chuyển hướng cho mọi người ngay lập tức.',
        'tour_enabled' => 'Hiển thị chuyến tham quan chào mừng',
        'tour_enabled_hint' => 'Chuyển người dùng tới chuyến tham quan có hướng dẫn trong lần đăng nhập đầu tiên.',
        'tour_version' => 'Phiên bản chuyến tham quan',
        'tour_version_hint' => 'Tăng số này để hiển thị lại chuyến tham quan cho mọi người đã xem. Giữ nguyên nếu chỉ sửa câu chữ.',
    ],

    'suspended' => [
        'title' => 'Máy chủ bị tạm ngưng',
        'body' => 'Máy chủ này đang bị tạm ngưng. Không thể khởi động hay quản lý máy chủ cho đến khi lệnh tạm ngưng được gỡ bỏ.',
        'by_abuse_scan' => 'Đã bị hệ thống quét lạm dụng tự động của chúng tôi tạm ngưng :time.',
        'by_admin' => 'Đã bị một quản trị viên tạm ngưng :time.',
        'by_system' => 'Đã bị tạm ngưng :time.',
        'deletes_at' => 'Máy chủ cùng các tệp của nó sẽ bị xóa trong :time, trừ khi lệnh tạm ngưng được gỡ bỏ trước đó.',
        'note_label' => 'Lý do',
        'findings_label' => 'Những gì hệ thống quét tìm thấy',
        'more' => 'và :count mục khác',
        'help' => 'Nếu bạn nghĩ đây là nhầm lẫn, hãy hỏi chúng tôi trên Discord và ghi kèm tên máy chủ.',
        'help_admin' => 'Nếu bạn nghĩ đây là nhầm lẫn, hãy liên hệ quản trị viên của bảng điều khiển này và ghi kèm tên máy chủ.',
        'help_action' => 'Nhận trợ giúp trên Discord',
        'reason_field' => 'Lý do',
        'reason_hint' => 'Hiển thị cho chủ sở hữu máy chủ trên trang thông báo tạm ngưng.',
    ],

    'maintenance' => [
        'title' => 'Bảo trì theo lịch',
        'badge' => 'Chế độ bảo trì',
        'heading' => 'Chúng tôi sẽ quay lại ngay',
        'subheading' => 'Chúng tôi đang tiến hành bảo trì theo lịch và nâng cấp hệ thống để cải thiện trải nghiệm của bạn. Dịch vụ sẽ sớm hoạt động trở lại.',
        'status_active' => 'Đang bảo trì hệ thống',
        'refresh_now' => 'Kiểm tra lại',
        'refresh_auto' => 'Tự kiểm tra lại sau :seconds',
        'discord' => 'Hỗ trợ trên Discord',
        'status_page' => 'Trạng thái công khai',
        'service_unavailable' => '503 • Dịch vụ tạm thời không khả dụng',
        'checking' => 'Đang kiểm tra…',
        'ad_title' => 'Quảng cáo',
    ],

    'errors' => [
        'home' => 'Quay lại bảng điều khiển',
        'status' => 'Trạng thái công khai',
        'code' => 'Lỗi :code',
        'titles' => [
            '400' => 'Yêu cầu không hợp lệ',
            '401' => 'Cần đăng nhập',
            '403' => 'Không có quyền truy cập',
            '404' => 'Không tìm thấy trang',
            '419' => 'Trang đã hết hạn',
            '429' => 'Quá nhiều yêu cầu',
            '500' => 'Đã xảy ra lỗi',
        ],
        'messages' => [
            '400' => 'Không thể xử lý yêu cầu này. Hãy quay lại và thử lại.',
            '401' => 'Đăng nhập để xem trang này.',
            '403' => 'Bạn không có quyền truy cập trang này.',
            '404' => 'Trang này không tồn tại hoặc đã được chuyển đi.',
            '419' => 'Trang này đã mở quá lâu. Hãy tải lại trang và thử lại.',
            '429' => 'Quá nhiều yêu cầu trong thời gian ngắn. Hãy chờ một lát rồi thử lại.',
            '500' => 'Chúng tôi gặp sự cố ở phía mình. Hãy thử lại sau giây lát.',
            'default' => 'Đã xảy ra lỗi với yêu cầu này.',
        ],
    ],

    'admin_nav' => [
        'servers' => 'Máy chủ',
        'users' => 'Người dùng',
        'community' => 'Thông báo & trạng thái',
        'subdomains' => 'Tên miền phụ',
        'panel' => 'Bảng điều khiển',
        'advanced' => 'Nâng cao',
    ],
];
