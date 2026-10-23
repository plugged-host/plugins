<?php

/*
    plugged.host Economy — English.

    The `credits` block moved here verbatim from plugged-host-core when the economy
    was split out, keys and all, so every `plugged-host-economy::strings.credits.*`
    call reads the same copy it always did. Do not renumber or rename those keys
    without grepping every plugin for them first.

    The `tokens` block is new. Tokens are what start credits were called before the
    split; rows written by the old code still say "start credit" in their stored
    description, which is why nothing here tries to restate history.

    Keep this file and de/strings.php in step — a key present in one and missing in
    the other renders as the raw key path to the user.
*/

return [
    /*
        The two store pages. Credits buy resources (ResourceStorePage, slug `store`); tokens
        buy plugged.host Plus and PlusPlus (TokenStorePage, slug `token-store`). The currencies
        are not interchangeable, which is why each has a page of its own.
    */
    'resource_store' => [
        'title' => 'Cửa hàng tài nguyên',
        'nav_label' => 'Cửa hàng tài nguyên',
        'subheading' => 'Dùng credit để mua thêm CPU, bộ nhớ và ổ đĩa. Những gì bạn mua là của bạn mãi mãi.',
        'what_credits_buy' => 'Credit dùng để mua CPU, bộ nhớ và ổ đĩa, giữ mãi mãi.',
        'balance_hint' => 'Kiếm credit bằng cách mời mọi người vào máy chủ Discord của chúng tôi hoặc boost máy chủ đó.',
        'resources_heading' => 'Tài nguyên của bạn',
        'resources_hint' => 'Phần sáng là những gì máy chủ của bạn đang dùng, phần tô nhạt là phần còn lại trong giới hạn của bạn, và thanh kéo dài đến mức mà giá bắt đầu tăng mạnh. Những bước đầu tiên rất rẻ; mỗi bước sau đắt hơn bước trước một chút.',
        'of_limit' => 'trên :limit',
        'meter_label' => 'Đang dùng :used trên :limit',
        'price_rising' => 'Mỗi bước đắt dần cho đến :limit, nơi giá đạt mức đầy đủ.',
        'price_climbing' => 'Vượt quá :limit, mỗi bước đều đắt hơn, và vượt quá :soft thì đắt hơn nhiều.',
        'price_steep' => 'Vượt quá :soft, mỗi bước đắt hơn bước trước rất nhiều.',
        'over' => 'Vượt giới hạn của bạn :amount. Hãy thu nhỏ hoặc xóa một máy chủ để giải phóng chỗ.',
        'history' => 'Lịch sử credit',
    ],

    'plus_page' => [
        'title' => ':app Plus',
        'nav_label' => ':app Plus',
        'subheading' => 'Vị trí tốt hơn, không phải xếp hàng chờ và không có quảng cáo trong game. Mua một lần, dùng mãi mãi.',
        'owned_heading' => 'Bạn đã có :app Plus',
        'owned_body' => 'Các vị trí Plus, quyền ưu tiên trong hàng chờ khởi động và không có quảng cáo trong game là của bạn mãi mãi. PlusPlus còn bổ sung thêm nữa.',
        'plus_pitch' => 'Mọi thứ mà hầu hết máy chủ cần, trả một lần, không cần gia hạn.',
        'plusplus_pitch' => 'Gói bổ sung cho những máy chủ cần nhiều nhất: phần cứng Premium, không quảng cáo ở bất kỳ đâu và thêm CPU.',
        'price_tokens' => ':cost token',
        'plusplus_term' => ':tokens, hoặc :credits nếu trả bằng credit',
        'plusplus_term_tokens' => ':tokens',
        'duration_weeks' => '{1} một tuần|{2} hai tuần|[3,*] :count tuần',
        'duration_days' => '{1} một ngày|[2,*] :count ngày',
        'credits' => 'credit',
        'get_plus' => 'Mua Plus',
        'extend_hint' => 'Mua lại trước khi hết hạn sẽ cộng thêm vào thời gian còn lại.',
        'compare_heading' => 'Bạn nhận được gì',
        'col_free' => 'Miễn phí',
        'col_plus' => 'Plus',
        'col_plusplus' => 'PlusPlus',
        'row_locations' => 'Vị trí',
        'row_queue' => 'Hàng chờ khởi động khi node bận',
        'row_game_ads' => 'Quảng cáo trong game (Minecraft)',
        'row_panel_ads' => 'Quảng cáo trong bảng điều khiển',
        'row_cpu' => 'CPU',
        'row_ai' => 'Mức sử dụng neo (AI)',
        'row_devbox' => 'VS Code trên máy chủ của bạn',
        'cell_locations_free' => 'Miễn phí',
        'cell_locations_plus' => 'Miễn phí và Plus',
        'cell_locations_plusplus' => 'Miễn phí, Plus và Premium',
        'cell_queue_free' => 'Xếp hàng chờ',
        'cell_queue_plus' => 'Trước người dùng miễn phí',
        'cell_queue_plusplus' => 'Ở đầu hàng',
        'cell_game_ads_free' => 'Một tin nhắn ngắn mỗi giờ',
        'cell_game_ads_plus' => 'Không có',
        'cell_game_ads_plusplus' => 'Không có',
        'cell_panel_ads_free' => 'Có',
        'cell_panel_ads_plus' => 'Có',
        'cell_panel_ads_plusplus' => 'Không có',
        'cell_cpu_free' => 'Hạn mức của bạn',
        'cell_cpu_plus' => 'Hạn mức của bạn',
        'cell_cpu_plusplus' => 'Hạn mức của bạn +:cpu%',
        'cell_ai_free' => 'Tiêu chuẩn',
        'cell_ai_plus' => '+:plus%',
        'cell_ai_plusplus' => '+:plusplus%',
        'cell_devbox_free' => 'Không',
        'cell_devbox_tier' => ':cpu vCPU, :memory RAM, :disk ổ đĩa',
        'faq_heading' => 'Câu hỏi',
        'faq_once_q' => 'Plus có phải là gói đăng ký định kỳ không?',
        'faq_once_a' => 'Không. Bạn chỉ trả cho Plus một lần và giữ nó mãi mãi. Chỉ PlusPlus có thời hạn nhất định, và không có gì tự động gia hạn.',
        'faq_expiry_q' => 'Điều gì xảy ra khi PlusPlus hết hạn?',
        'faq_expiry_a' => 'Bạn vẫn giữ Plus. Các máy chủ ở vị trí Premium sẽ được chuyển sang vị trí khác, và nếu máy chủ của bạn dùng nhiều CPU hơn hạn mức khi không có PlusPlus, phần chênh lệch sẽ được trừ đều trên các máy chủ. Hãy mua lại PlusPlus trước khi hết hạn để tránh cả hai điều này.',
        'faq_tokens_q' => 'Làm thế nào để nhận token?',
        'faq_tokens_a' => 'Trên trang Token ở thanh bên, bằng cách hoàn thành các liên kết tài trợ.',
        'faq_credits_q' => 'Tôi có thể trả bằng credit không?',
        'faq_credits_a' => 'Với PlusPlus thì có: mỗi lần trả bằng credit sẽ mua được :duration. Bản thân Plus được trả bằng token.',
    ],

    'store' => [
        'balance_after' => 'số dư còn :balance',
    ],

    'credits' => [
        'credits' => 'Credit',
        'credits_short' => 'cr',
        'earn_discord_rewards' => 'Nhận phần thưởng Discord',
        'confirm_purchase' => 'Bạn có chắc muốn mua :item với giá :cost credit không?',
        'purchase_successful' => 'Mua thành công',
        'purchase_failed' => 'Mua thất bại',
        'insufficient_credits' => 'Bạn không có đủ credit cho giao dịch mua này.',
        'store_unavailable' => 'Hiện không thể mua CPU, bộ nhớ và ổ đĩa. Vui lòng thử lại sau một phút.',
        'invalid_amount' => 'Không thể mua số lượng đó trong một lần.',
        'cpu_purchased' => 'Đã nâng giới hạn CPU của bạn thêm +:amount% với giá :cost credit.',
        'memory_purchased' => 'Đã nâng giới hạn RAM của bạn thêm +:amount :unit với giá :cost credit.',
        'disk_purchased' => 'Đã nâng giới hạn ổ đĩa của bạn thêm +:amount :unit với giá :cost credit.',
        'tx_cpu_purchase' => 'Đã mua nâng cấp giới hạn CPU +:amount%',
        'tx_memory_purchase' => 'Đã mua nâng cấp giới hạn RAM +:amount :unit',
        'tx_disk_purchase' => 'Đã mua nâng cấp dung lượng ổ đĩa +:amount :unit',
        'no_transactions' => 'Chưa có giao dịch credit nào.',
        'tx_economy_reset' => 'Đặt lại hệ thống credit',
        'tx_invite_clawback' => 'Thu hồi phần thưởng mời — thành viên được mời đã rời Discord',
        'tx_resource_reclaim' => 'Thu hồi tài nguyên đã mua để bù cho phần thưởng bị thu hồi',
    ],

    'tokens' => [
        'tokens' => 'Token',
        'balance' => 'Số dư token',
        'earn_tokens' => 'Kiếm token',
        'tx_daily' => 'Token hằng ngày',
    ],

    /*
        plugged.host Plus and PlusPlus (MembershipService). Prices and days come from
        config through the service, never written out here.
    */
    'membership' => [
        'heading' => ':app Plus',
        'description' => 'Plus là của bạn mãi mãi. PlusPlus bổ sung thêm trên nền Plus trong vài ngày mỗi lần, và cần mua lại để tiếp tục giữ.',
        'plus_title' => ':app Plus',
        'plus_term' => 'Một lần, dùng mãi mãi',
        'perk_plus_locations' => 'Các vị trí Plus',
        'perk_priority' => 'Ưu tiên trong hàng chờ khởi động khi node bận',
        'perk_no_game_ads' => 'Không có quảng cáo trong game trên các máy chủ Minecraft của bạn',
        'plusplus_title' => ':app PlusPlus',
        'plusplus_term' => 'Cần có Plus',
        'perk_everything_in_plus' => 'Mọi thứ trong Plus',
        'perk_premium_locations' => 'Các vị trí Premium',
        'perk_no_ads' => 'Không quảng cáo ở bất kỳ đâu: không banner, không cửa sổ bật lên, không màn hình chờ trước khi khởi động và không quảng cáo trong game',
        'perk_front_of_queue' => 'Đứng đầu hàng chờ khởi động, trước cả Plus',
        'perk_cpu' => '+:cpu% CPU cho tài khoản của bạn',
        'perk_ai_plus' => 'Thêm :percent% mức sử dụng neo (AI)',
        'perk_ai_plusplus' => 'Thêm :percent% mức sử dụng neo (AI) nữa, ngoài phần của Plus',
        'perk_devbox' => 'VS Code trên máy chủ của bạn: :cpu vCPU, :memory RAM, :disk ổ đĩa',
        'perk_devbox_plusplus' => 'Devbox VS Code lớn hơn: :cpu vCPU, :memory RAM, :disk ổ đĩa',
        'plusplus_expiry' => 'Khi PlusPlus hết hạn, các máy chủ ở vị trí Premium sẽ được chuyển sang vị trí khác, và phần CPU vượt hạn mức sẽ được trừ đều trên các máy chủ của bạn. Mua lại trước thời điểm đó sẽ cộng thêm vào thời gian còn lại.',
        'plusplus_active_heading' => 'PlusPlus đang hoạt động',
        'plusplus_active_body' => 'Có hiệu lực đến :until.',
        'owned' => 'Đã sở hữu',
        'days' => ':days ngày',
        'tokens_short' => 'token',
        'cta_tokens' => ':cost token',
        'plus_confirm' => 'Dùng :cost token để mua :app Plus? Nó sẽ là của bạn mãi mãi.',
        'plusplus_confirm_tokens' => 'Dùng :cost token để mua :days ngày :app PlusPlus?',
        'plusplus_confirm_credits' => 'Dùng :cost credit để mua :days ngày :app PlusPlus?',
        'balance_tokens' => 'Bạn có :balance token.',
        'earn_link' => 'Nhận thêm token',
        'needs_plus' => 'PlusPlus yêu cầu có :app Plus trước.',
        'plus_bought' => 'Bạn đã có :app Plus.',
        'plusplus_bought' => 'PlusPlus có hiệu lực đến :until.',
        'short_tokens' => 'Bạn cần :cost token để làm điều đó.',
        'short_credits' => 'Bạn cần :cost credit để làm điều đó.',
        'unavailable' => 'Plus hiện không được bán.',
        'failed' => 'Giao dịch không thành công. Bạn chưa bị trừ gì.',
        'tx_plus' => ':app Plus',
        'tx_plusplus' => ':app PlusPlus, :days ngày',
    ],

    /*
    | The Economy tab on the admin user page. Support-facing, not user-facing: the
    | wording assumes whoever is reading it knows what a ledger is and needs to know
    | that this writes one, because the alternative — editing the balance column —
    | is the thing this tab exists to stop anyone from wanting to do.
    */
    'admin' => [
        'tab' => 'Kinh tế',
        'credits_heading' => 'Credit',
        'credits_hint' => 'Credit dùng để mua CPU, bộ nhớ và ổ đĩa trong cửa hàng. Nhận được từ lời mời và boost Discord.',
        'tokens_heading' => 'Token',
        'tokens_hint' => 'Token dùng để mua :app Plus và PlusPlus. Nhận được từ các liên kết tài trợ.',
        'balance_current' => 'Số dư: :amount',
        'balance_unavailable' => 'Chưa có số dư nào được ghi nhận',
        'set_credits' => 'Đặt credit',
        'set_tokens' => 'Đặt token',
        'modal_credits' => 'Đặt số dư credit',
        'modal_tokens' => 'Đặt số dư token',
        'modal_description' => 'Phần chênh lệch được ghi vào sổ cái dưới dạng một điều chỉnh, để số dư và lịch sử giao dịch không bao giờ lệch nhau. Đặt số dư bằng đúng giá trị hiện tại sẽ không ghi gì cả.',
        'balance_field' => 'Số dư mới',
        'balance_field_hint' => 'Hiện tại: :amount.',
        'note_field' => 'Ghi chú',
        'note_field_hint' => 'Không bắt buộc. Được lưu trên mục sổ cái cạnh tên bạn — thường chỉ cần số ticket.',
        'tx_description' => 'Được điều chỉnh bởi :admin',
        'tx_description_note' => 'Được điều chỉnh bởi :admin — :note',
        'notify_set' => 'Đã đặt số dư thành :amount.',
        'notify_unchanged' => 'Số dư đã là :amount. Không có gì được ghi.',
        'notify_failed' => 'Điều chỉnh không thành công. Không có gì thay đổi.',
    ],
    // Tokens — earn page + ledger (moved here from plugged-host-ads, WP1 earn-half)
    // Tokens — the earn page
    'tokens_nav' => 'Token',
    'tokens_title' => 'Token',
    'tokens_subheading' => 'Kiếm token và xem chúng đã được dùng vào đâu',
    'tokens_balance' => ':count token',
    'tokens_explainer' => 'Kiếm token bằng cách hoàn thành các liên kết tài trợ. Token dùng để trả cho :app Plus, đánh thức máy chủ đang ngủ đông và tên miền phụ.',
    'tokens_none' => 'Hiện chưa có cách nào để kiếm token.',
    'tokens_reward' => ':count token mỗi lần hoàn thành',
    'tokens_used' => ':used trên :cap hôm nay',
    'tokens_capped' => 'Hãy quay lại vào ngày mai',
    'tokens_link_unavailable' => 'Hiện không khả dụng',
    'tokens_history' => 'Hoạt động gần đây',
    'tokens_earn_with' => 'Kiếm với :provider',

    /*
        The block this plugin renders onto plugged-host-economy's Economy page, under
        the token wallet. Same offers as the earn page; the CTA is a link to that page
        rather than the sponsored anchor itself, because the anchor only earns if
        linkvertise.js rewrote it — which only happens where the script is loaded.
    */
    'tokens_store_heading' => 'Kiếm token',
    'tokens_store_hint' => 'Hoàn thành một liên kết tài trợ và token sẽ có trong số dư của bạn khi bạn quay lại.',

    // Tokens — ledger descriptions
    'start_provider_linkvertise' => 'Linkvertise',
    'start_provider_vylo' => 'Vylo',
    'tokens_tx_earn' => 'Kiếm được với :provider',

    // Tokens — results
    'tokens_result_granted' => 'Đã cộng: +:amount token',
    'tokens_result_settled' => 'Lượt này đã được trả thưởng rồi',
    'tokens_result_expired' => 'Mất quá nhiều thời gian — vui lòng bắt đầu lại',
    'tokens_result_no_token' => 'Nhà cung cấp đã đưa bạn quay lại mà không có mã hoàn thành',
    'tokens_result_no_claim' => 'Không tìm thấy lượt thực hiện nào đang chờ cho tài khoản của bạn',
    'tokens_result_replay' => 'Mã hoàn thành đó đã được sử dụng',
    'tokens_result_capped' => 'Bạn đã đạt giới hạn hôm nay cho lựa chọn này',
    'tokens_result_throttled' => 'Quá nhiều lần thử — vui lòng chậm lại',
    'tokens_result_invalid' => 'Không thể xác minh, nên không có token nào được cộng',
    'tokens_result_error' => 'Đã xảy ra lỗi. Không có token nào được cộng.',

    'perks' => [
        'heading' => 'Tận dụng tài khoản của bạn nhiều hơn',
        // The dashboard's collapsed one-line variant. The count belongs in the heading because
        // a closed bar has to say how much is behind it, or nobody opens it.
        'heading_compact' => 'Mở khóa thêm tài nguyên (:count)',
        'description' => 'Những nâng cấp bạn chưa nhận. Mỗi mục sẽ rời khỏi danh sách này khi bạn đã có nó.',
        'discord_link_title' => 'Liên kết Discord để nhận :gain',
        'discord_link_body' => 'Kết nối tài khoản Discord và tham gia các máy chủ Discord của chúng tôi để tăng giới hạn miễn phí.',
        'discord_link_cta' => 'Liên kết Discord',
        'discord_join_title' => 'Tham gia các máy chủ Discord của chúng tôi để nhận :gain',
        'discord_join_body' => 'Mỗi máy chủ Discord của chúng tôi mà bạn tham gia sẽ cộng thêm RAM cho tài khoản, và tham gia tất cả sẽ được cộng nhiều hơn.',
        'discord_join_cta' => 'Xem máy chủ',
        'discord_earn_title' => 'Nhận :credits credit cho mỗi người bạn mời',
        'discord_earn_body' => 'Mời mọi người vào Discord của chúng tôi bằng liên kết mời của riêng bạn, rồi dùng credit để mua thêm tài nguyên.',
        'discord_earn_body_boost' => 'Mời mọi người bằng liên kết mời của riêng bạn, hoặc boost máy chủ để nhận :credits credit, rồi dùng tất cả để mua thêm tài nguyên.',
        'discord_earn_cta' => 'Xem lời mời',
        'store_title' => ':price credit mua được thêm :amount RAM',
        'store_body' => 'Bạn có :balance credit. Hãy dùng chúng để mua CPU, RAM hoặc dung lượng ổ đĩa.',
        'store_cta' => 'Mở cửa hàng',
        'gain_cpu' => '+:amount CPU',
        'gain_memory' => '+:amount RAM',
        'gain_disk' => '+:amount dung lượng ổ đĩa',
        'gain_and' => 'và',
    ],
];
