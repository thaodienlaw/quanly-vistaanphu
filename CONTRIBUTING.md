# Cách đóng góp

Cảm ơn bạn đã giúp hoàn thiện trang quản lý của chung cư.

## Góp ý không cần biết lập trình

Mở một [Issue](../../issues/new/choose), chọn mẫu “Góp ý tính năng” hoặc “Báo lỗi”, và mô tả bằng tiếng Việt. Ví dụ hay: “Kế toán cần xuất báo cáo thu chi theo quý để trình Ban quản trị”.

**Không đăng** mật khẩu, số liệu thật của cư dân, số căn hộ gắn với tên người, hay số tài khoản ngân hàng.

## Sửa mã

1. Fork repo, tạo nhánh từ `main` (ví dụ `them-bao-cao-quy`).
2. Chạy thử: tạo `config.php` theo README, rồi `php -S 127.0.0.1:8080`.
3. Giữ trang đơn giản: HTML, CSS, JavaScript thuần, không thêm bước build. PHP 8 và SQLite.
4. Mọi chuỗi hiển thị bằng tiếng Việt có dấu. Giá trị người dùng nhập phải qua hàm `esc()` trước khi đưa vào HTML.
5. Mở Pull Request, ghi rõ thay đổi giúp ai và cách kiểm tra.

Ban quản trị xem xét và quyết định đưa vào trang chạy thật.
