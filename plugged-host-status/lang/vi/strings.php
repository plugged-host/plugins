<?php

return [
    'settings' => [
        'section_export' => 'Xuất dữ liệu cho trang trạng thái',
        'section_export_hint' => 'Nơi ghi các tệp JSON và RSS mà trang trạng thái tĩnh đọc, và URL công khai dùng trong feed.',
        'export_path' => 'Đường dẫn xuất',
        'export_path_hint' => 'Thư mục ghi các tệp JSON và feed.xml. Để trống để tắt việc xuất — snapshot vẫn được thu thập và lưu lại.',
        'rss_base_url' => 'URL trang trạng thái',
        'rss_base_url_hint' => 'URL công khai của trang trạng thái, chỉ dùng cho liên kết trong RSS feed. Để trống nếu bạn không công khai feed.',

        'section_probes' => 'Kiểm tra tình trạng',
        'section_probes_hint' => 'Các endpoint được kiểm tra mỗi phút để xác nhận trang web công khai và bảng điều khiển còn truy cập được.',
        'site_health_url' => 'URL kiểm tra trang web',
        'site_health_url_hint' => 'Endpoint được kiểm tra để xác nhận trang web công khai. Để trống để bỏ qua việc kiểm tra trang web: khi đó dòng Website không xuất hiện trên trang trạng thái và không được tính vào trạng thái chung.',
        'site_health_host' => 'Header Host khi kiểm tra trang web',
        'site_health_host_hint' => 'Header Host tùy chọn gửi kèm khi kiểm tra trang web, dùng khi endpoint được truy cập qua IP.',
        'panel_health_url' => 'URL kiểm tra bảng điều khiển',
        'panel_health_url_hint' => 'Endpoint được kiểm tra để xác nhận bảng điều khiển. Để trống để bỏ qua việc kiểm tra bảng điều khiển: khi đó dòng Panel không xuất hiện trên trang trạng thái và không được tính vào trạng thái chung.',

        'section_retention' => 'Lưu giữ & ngưỡng',
        'section_retention_hint' => 'Lịch sử được giữ bao lâu trước khi bị dọn, và khi nào một node bị coi là đang quá tải.',
        'history_days' => 'Thời gian lưu lịch sử (ngày)',
        'history_days_hint' => 'Số ngày giữ snapshot trước khi các dòng cũ hơn bị dọn.',
        'resource_threshold_percent' => 'Ngưỡng tải CPU (%)',
        'resource_threshold_percent_hint' => 'Mức dùng CPU bằng hoặc cao hơn tỷ lệ phần trăm này trong năm lần kiểm tra liên tiếp sẽ đánh dấu node là suy giảm. Một node chỉ đầy (bộ nhớ hoặc ổ đĩa) vẫn được tính là hoạt động bình thường.',
    ],

    'admin' => [
        'status_page' => 'Trang trạng thái',
    ],
];
