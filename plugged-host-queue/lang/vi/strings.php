<?php

/*
|--------------------------------------------------------------------------
| plugged.host Queue — English strings
|--------------------------------------------------------------------------
|
| Keep this file and lang/de/strings.php on the same key set. A key present in
| one and missing from the other renders as the raw dotted path on a page a
| paying customer is looking at.
|
| Tone rule for everything on the waiting page: say what is happening and why,
| in the fewest words that are still true. Somebody whose server will not start
| is already annoyed, and a cheerful euphemism for "the machine is full" is how
| that becomes a ticket.
|
*/

return [
    // The page itself.
    'page_title' => 'Đang chờ khởi động',
    'page_subheading' => 'Yêu cầu khởi động :server của bạn đang ở trong hàng chờ.',

    'heading' => 'Đang chuẩn bị máy chủ của bạn',
    'body_start' => 'Node này hiện đang bận. Máy chủ của bạn sẽ tự động khởi động ngay khi có chỗ — bạn không cần bấm gì cả.',
    'body_restart' => 'Node này hiện đang bận. Lệnh khởi động lại của bạn sẽ tự động chạy ngay khi có chỗ — máy chủ của bạn vẫn tiếp tục chạy cho đến lúc đó.',

    // Position in the line.
    'position_label' => 'Vị trí của bạn',
    'position_value' => 'Thứ :position trong hàng chờ',
    'position_next' => 'Bạn là người tiếp theo',
    'position_priority' => 'Ưu tiên',

    'waited' => 'Đã chờ :time',
    'cap_note' => 'Khởi động muộn nhất trong vòng :seconds giây',
    'cap_reached' => 'Đang khởi động',

    // Why the wait is happening, over the live reading the release uses.
    'reason_memory' => 'Bộ nhớ trên node này đang vượt quá :limit%. Khởi động thêm một máy chủ lúc này sẽ làm chậm mọi máy chủ trên node, kể cả máy chủ của bạn.',
    'reason_cpu' => 'CPU trên node này đang vượt quá :limit%. Khởi động thêm một máy chủ lúc này sẽ làm chậm mọi máy chủ trên node, kể cả máy chủ của bạn.',
    'reason_disk' => 'Ổ đĩa trên node này đang vượt quá :limit%. Khởi động thêm một máy chủ lúc này sẽ làm chậm mọi máy chủ trên node, kể cả máy chủ của bạn.',
    'reason_cleared' => 'Node đã có chỗ trở lại. Máy chủ của bạn đang khởi động.',

    'usage_memory' => 'Bộ nhớ',
    'usage_cpu' => 'CPU',
    'usage_disk' => 'Ổ đĩa',

    // Priority — what tokens buy. Two tiers; prices and lengths come from the config,
    // never from this file, so an admin who changes one does not leave a lie behind.
    'priority_plus_heading' => 'Bạn đang có :app Plus',
    'priority_plus_body' => 'Plus đưa máy chủ của bạn lên trước tất cả những người không có Plus trong hàng chờ này.',
    'priority_plusplus_heading' => 'Bạn đang có :app PlusPlus',
    'priority_plusplus_body' => 'PlusPlus đưa máy chủ của bạn lên đầu hàng chờ này, trước cả Plus.',
    'plus_offer_heading' => 'Đi trước với :app Plus',
    'plus_offer_body' => 'Plus có giá :cost token, trả một lần và thuộc về bạn mãi mãi: ưu tiên trong hàng chờ này cho tất cả máy chủ của bạn, các vị trí Plus và không có quảng cáo trong game.',
    'plus_offer_cta' => 'Mua Plus',
    'tokens_tx_priority_replaced' => 'Hoàn tiền — lượt ưu tiên đã được thay bằng :app Plus',

    // The transfer offer.
    'transfer_heading' => 'Chuyển sang một node ít bận hơn',
    'transfer_body' => 'Chúng tôi có thể chuyển máy chủ này sang :node, node này không bận. Các tệp của bạn sẽ được chuyển theo.',
    'transfer_note' => 'Máy chủ sẽ ngoại tuyến trong lúc sao chép và địa chỉ của máy chủ sẽ thay đổi. Máy chủ sẽ tự khởi động lại khi việc chuyển hoàn tất.',
    'transfer_confirm' => "Chuyển máy chủ này sang :node?\n\nMáy chủ sẽ ngoại tuyến cho đến khi sao chép xong, và địa chỉ IP cùng cổng của máy chủ sẽ thay đổi. Mọi thứ trỏ đến địa chỉ cũ — tên miền, dấu trang, danh sách máy chủ của người chơi — đều phải được cập nhật.",
    'transfer_cta' => 'Chuyển máy chủ',
    'transfer_too_big' => 'Máy chủ này quá lớn để chuyển nhanh, nên chờ ở đây là lựa chọn nhanh hơn.',

    // The sponsored link.
    'direct_heading' => 'Một chút gì đó để xem trong lúc chờ',
    'direct_body' => 'Mở trong một thẻ mới. Vị trí của bạn trong hàng chờ vẫn được giữ, và thời gian chờ vẫn tiếp tục được tính ở đây.',
    'direct_cta' => 'Mở',

    // Leaving.
    'leave_cta' => 'Hủy và quay lại',
    'pass_note' => 'Sau khi máy chủ của bạn khởi động, các lần khởi động trong :minutes phút tiếp theo sẽ bỏ qua trang này.',

    // Token ledger entries. These end up in the user's own transaction list.

    // Notifications.
    'notify_conflict' => 'Hiện không thể khởi động máy chủ này, nên lượt chờ trong hàng chờ đã bị hủy.',
    'notify_transfer_started' => 'Việc chuyển đã bắt đầu. Máy chủ của bạn sẽ hoạt động trở lại khi các tệp được sao chép xong.',
    'notify_transfer_no_node' => 'Hiện không có node nào ít bận hơn để chuyển máy chủ này sang.',
    'notify_transfer_conflict' => 'Máy chủ này đang bận việc khác nên hiện không thể chuyển.',
    'notify_transfer_unavailable' => 'Tính năng chuyển máy chủ hiện không khả dụng.',
    'notify_transfer_failed' => 'Không thể bắt đầu việc chuyển. Không có gì thay đổi.',

    /*
    |--------------------------------------------------------------------------
    | Admin settings form
    |--------------------------------------------------------------------------
    |
    | Admin Area → Plugins → plugged.host Queue. The helpers are written for
    | somebody who has not read the source: each one says what the number does
    | to a real user, not what field it maps to.
    |
    */
    'section_general' => 'Hàng chờ khởi động',
    'section_general_desc' => 'Mỗi lần khởi động của một người dùng không được miễn sẽ chờ trên một trang ngắn và được cho đi lần lượt từng máy chủ một trên mỗi node, khi node đã xuống dưới ngưỡng bận, để thêm một container không làm mọi máy chủ trên một node đang bận chạy tệ hơn.',
    'enabled' => 'Đưa lượt khởi động vào hàng chờ',
    'enabled_helper' => 'Mỗi lần khởi động của một người dùng không được miễn và không có lượt bỏ qua sẽ chờ ít nhất bằng thời gian chờ tối thiểu, sau đó được cho đi một lượt mỗi node sau mỗi khoảng cách giữa các lượt, khi node đang dưới ngưỡng bận. Khi tắt, các lượt khởi động luôn được thực hiện ngay.',
    'include_restart' => 'Đưa cả lượt khởi động lại vào hàng chờ',
    'include_restart_helper' => 'Khởi động lại sẽ bật lại một container, nên tốn cùng mức dư địa như một lần khởi động. Máy chủ vẫn chạy trong lúc người dùng chờ.',
    'exempt_roles_skip' => 'Vai trò được miễn bỏ qua hàng chờ',
    'exempt_roles_skip_helper' => 'Dùng danh sách vai trò được miễn từ plugin plugged.host Ads thay vì giữ thêm một danh sách thứ hai. Root admin luôn được bỏ qua.',
    'pressure_dimensions' => 'Thế nào được tính là bận',
    'pressure_dimensions_helper' => 'Phân tách bằng dấu phẩy: memory, cpu, disk. Một node vượt ngưỡng bận ở bất kỳ mục nào trong số này sẽ không cho ai đi cho đến khi có chỗ trở lại hoặc hết thời gian chờ tối đa. Không khuyến nghị dùng disk — ổ đĩa đầy không tự trống ra trong lúc ai đó chờ, nên nó tạo ra một hàng chờ không bao giờ nhúc nhích.',
    'threshold_percent' => 'Ngưỡng bận',
    'threshold_percent_helper' => 'Một node vượt ngưỡng này ở các mục bên trên sẽ giữ hàng chờ của nó cho đến khi xuống dưới ngưỡng trở lại, và trong lúc đó không nhận máy chủ mới (tạm dừng triển khai trong plugged.host Servers, với ngưỡng thấp hơn trên các node nhỏ). Ngưỡng thấp hơn sẽ giữ lượt khởi động lại sớm hơn; ngưỡng cao hơn cho phép node được lấp đầy hơn trước khi có ai phải chờ lâu hơn mức tối thiểu.',

    'section_timing' => 'Thời gian',
    'section_timing_desc' => 'Một lượt khởi động có thể bị giữ trong bao lâu, và các lượt khởi động được cho vào một node nhanh đến mức nào.',
    'min_wait' => 'Thời gian chờ tối thiểu',
    'min_wait_helper' => 'Mỗi lượt khởi động trong hàng chờ đều chờ ít nhất chừng này, kể cả trên một node đang rảnh, để trang chờ không bao giờ chỉ lóe lên rồi biến mất.',
    'max_wait' => 'Thời gian chờ tối đa',
    'max_wait_helper' => 'Mức trần. Quá mức này, lượt khởi động sẽ được thực hiện bất kể tình trạng node — một hàng chờ không có mức trần chỉ là một sự cố có thanh tiến trình. Tự động được nâng lên nếu thấp hơn mức tối thiểu.',
    'release_interval' => 'Khoảng cách giữa các lượt',
    'release_interval_helper' => 'Theo từng node: mỗi khoảng cách chỉ cho đi một lượt khởi động, để cả một hàng lượt khởi động không bao giờ đổ vào một node cùng lúc. Các lượt đã chạm mức trần bỏ qua giới hạn này.',
    'stale_after' => 'Bỏ các lượt im lặng sau',
    'stale_after_helper' => 'Chỉ áp dụng cho các lượt chưa bao giờ đủ điều kiện được cho đi. Một thẻ đã đóng vẫn được khởi động — đợt quét sẽ thực hiện nó — nên đây không phải là cách làm mất một yêu cầu.',
    'pass_minutes' => 'Bỏ qua cả hai lượt chờ trong (phút)',
    'pass_minutes_helper' => 'Sau khi được cho đi, các lần khởi động tiếp theo của người dùng này trên máy chủ đó sẽ bỏ qua hàng chờ này và cổng quảng cáo. Ngồi chờ hết một lượt rồi lại rơi vào lượt chờ kia là điều tệ nhất mà tính năng này có thể gây ra.',


    'section_transfer' => 'Chuyển khỏi một node đang bận',
    'section_transfer_desc' => 'Lối thoát còn lại khỏi hàng chờ: chuyển máy chủ sang một node không bận. Được đề xuất trên trang chờ, luôn do người dùng tự chọn, không bao giờ tự động.',
    'transfer_enabled' => 'Đề xuất chuyển máy chủ',
    'transfer_enabled_helper' => 'Khi tắt, chờ là cách duy nhất để qua hàng chờ.',
    'transfer_require_priority' => 'Yêu cầu :app Plus',
    'transfer_require_priority_helper' => 'Khi bật, việc chuyển là một phần của những gì Plus mang lại. Khi tắt, bất kỳ ai bị kẹt trong hàng chờ đều có thể chuyển.',
    'transfer_max_disk' => 'Ẩn đề xuất khi bằng hoặc vượt',
    'transfer_max_disk_helper' => 'Một lần chuyển sẽ sao chép toàn bộ thư mục dữ liệu. Quá vài gigabyte thì đó không còn là lối thoát nhanh nữa. Máy chủ có dung lượng ổ đĩa không giới hạn sẽ không bao giờ được đề xuất.',
    'transfer_same_location' => 'Chỉ cùng vị trí',
    'transfer_same_location_helper' => 'Khi bật, chỉ xét các node có chung thẻ vị trí với node hiện tại. Khi tắt, mọi node khả dụng đều được xét, điều này có thể chuyển ai đó sang một châu lục khác.',
];
