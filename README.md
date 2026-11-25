# Quản lý The Vista An Phú

Trang web đơn giản để Ban quản trị chung cư The Vista An Phú quản lý:

- **Quỹ bảo trì** và **quỹ vận hành**: ghi khoản thu, khoản chi, xem số dư và biểu đồ 6 tháng.
- **Tài sản**: thiết bị, máy móc, xe, kèm tình trạng và chu kỳ bảo trì.
- **Bảo trì**: nhật ký bảo trì, lịch tới hạn, tự ghi chi phí vào quỹ.
- **Chứng từ**: đính kèm ảnh, PDF hóa đơn vào giao dịch, lần bảo trì, tài sản (lưu ngoài thư mục web).
- **Thông báo và kế hoạch** của Ban quản lý, Ban quản trị (ngày, tháng, năm), cư dân xem được.
- **Hồ sơ tài sản**: nguồn gốc, lịch sử sử dụng và bảo trì, chứng từ, kế hoạch, bình luận của cư dân.
- **Căn hộ** (chỉ Ban quản trị, Ban quản lý thấy), **đăng ký gửi xe** (cư dân chỉ thấy xe của căn mình, Ban quản lý duyệt) và **biểu quyết** mỗi căn một phiếu, tính theo số căn và diện tích.
- **Tiện ích**: sân tennis, phòng cộng đồng… đặt chỗ theo khung giờ, tự chặn trùng giờ.
- **Nhà thầu**: hồ sơ, hợp đồng (số, thời hạn, giá trị, tệp đính kèm), điểm hài lòng 4 tiêu chí, công việc và chi phí đã ghi; cảnh báo hợp đồng sắp hết hạn. Cư dân chỉ thấy tên, dịch vụ, tình trạng và điểm; số điện thoại, giá trị, ghi chú, tệp do Ban quản trị/Ban quản lý xem.
- **Kế hoạch và báo cáo hoạt động**: nằm trong tab Báo cáo (chọn "Kế hoạch và báo cáo hoạt động"): Kế hoạch ngày/tháng/quý/năm, Báo cáo tuần/tháng/quý/năm, chọn đơn vị đăng (Đơn vị quản lý vận hành, Ban quản lý, Ban quản trị), đính kèm tệp; cư dân xem được. Tab Thông báo chỉ còn thông báo.
- **Báo cáo** có thêm tổng hợp nhà thầu, hoạt động Ban quản lý, Ban quản trị và tiến độ thực hiện các biểu quyết.
- **Báo cáo** tháng, quý, năm, in hoặc lưu PDF.
- **Nhắc việc**: bảo trì, kiểm định, hết bảo hành; gửi email hằng ngày khi có người của Ban quản trị mở trang.
- **Tài khoản riêng** (Trưởng ban, Kế toán), nhật ký thay đổi, hủy khoản thay cho xóa, khoản chi lớn cần Trưởng ban khác duyệt.
- **Sao lưu, khôi phục** dữ liệu (.json) và tải chứng từ (.zip).
- **Góp ý**: nút “Góp ý” trên đầu trang cho mọi người gửi ý kiến.

Chạy tại: https://quanly.vistaanphu.com

## Công nghệ

Một trang `index.html` (HTML, CSS, JavaScript thuần, không cần build) và một tệp `api.php` (PHP 8 + SQLite). Chạy được trên hosting PHP thông thường, không cần MySQL.

## Cài đặt

1. Sao chép `config.sample.php` thành `config.php`.
2. Tạo mã băm mật khẩu và dán vào `config.php`:
   ```
   php -r 'echo password_hash("mat-khau-cua-ban", PASSWORD_DEFAULT), "\n";'
   ```
   - `editor_hash`: mật khẩu Ban quản trị và kế toán (xem và sửa).
   - `viewer_hash`: mật khẩu cư dân (chỉ xem). Để trống nếu không cho cư dân xem.
3. Đặt `db` trỏ tới một thư mục **ngoài** thư mục web (trên Hostinger, mặc định nằm cạnh `public_html`).
4. Vào trang, tạo tài khoản riêng ở mục Quản trị, đặt hạn mức duyệt chi và email nhận nhắc việc ở mục Cài đặt.
5. Tải các tệp `index.html`, `api.php`, `.htaccess`, `config.php` lên thư mục web.

Chạy thử trên máy: `php -S 127.0.0.1:8080` rồi mở http://127.0.0.1:8080.

## Bảo mật

- Số liệu quỹ cần đăng nhập mới xem được. Đăng nhập sai quá 8 lần trong 10 phút sẽ bị khóa tạm.
- `config.php` và tệp dữ liệu bị `.htaccess` chặn; đừng đưa `config.php` lên GitHub.
- Góp ý của người dùng lưu trong cơ sở dữ liệu, chỉ tài khoản sửa được mới đọc.
- Phát hiện lỗi bảo mật, xin báo riêng theo [SECURITY.md](SECURITY.md), đừng đăng công khai.

## Góp ý và đóng góp

- Có ý kiến hoặc gặp lỗi: dùng nút **Góp ý** trên trang, hoặc [mở một Issue](../../issues/new/choose).
- Muốn sửa mã: xem [CONTRIBUTING.md](CONTRIBUTING.md).

## Giấy phép

MIT, xem [LICENSE](LICENSE).
