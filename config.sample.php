<?php
// Sao chép thành config.php rồi điền. KHÔNG đưa config.php lên GitHub.
// Tạo hash mật khẩu:  php -r 'echo password_hash("mat-khau-cua-ban", PASSWORD_DEFAULT), "\n";'
return [
    // Nơi lưu dữ liệu. Nên đặt NGOÀI thư mục public_html.
    'db' => dirname(__DIR__) . '/quanly-data/quanly.sqlite',
    // Mật khẩu của Ban quản trị / kế toán: xem và sửa.
    'editor_hash' => '',
    // Mật khẩu của cư dân: chỉ xem. Để trống nếu không cho cư dân xem.
    'viewer_hash' => '',
];
