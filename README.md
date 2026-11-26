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
- **Kế hoạch và báo cáo hoạt động**: nằm trong tab Báo cáo (chọn "Kế hoạch và báo cáo hoạt động"): Kế hoạch ngày/tháng/quý/năm, Báo cáo tuần/tháng/quý/năm, chọn đơn vị đăng (Ban quản lý, tức đơn vị quản lý vận hành, hoặc Ban quản trị), đính kèm tệp; cư dân xem được. Tab Thông báo chỉ còn thông báo.
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

## Phân quyền

| Vai trò | Quyền chính |
|---|---|
| Cư dân | Xem số liệu chung, bình luận, biểu quyết, đăng ký xe và tiện ích của căn mình |
| Kế toán | Ghi, sửa, hủy thu chi và chứng từ; không duyệt chi |
| Ban quản lý (đơn vị vận hành) | Thu chi, tài sản, bảo trì, nhà thầu, thông báo, báo cáo, duyệt gửi xe và tiện ích, quản lý tiện ích |
| Ban quản trị | Duyệt chi lớn, biểu quyết, danh sách căn hộ, tiện ích, nhật ký; không ghi thu chi |
| Ban kiểm tra | Chỉ xem toàn bộ, kể cả nhật ký |
| Trưởng ban (admin) | Toàn quyền, tài khoản, cài đặt, sao lưu |
| Kế toán, Ban quản lý (vai trò cũ) | Giữ nguyên quyền như trước khi tách vai trò |

Người ghi khoản chi lớn không tự duyệt được khoản đó.

## Nhóm, tài khoản và quy tắc mã

Trong tab **Quản trị** (chỉ Trưởng ban thấy):

- **Nhóm và phân quyền**: tạo nhóm (Quản lý chung, Kỹ thuật, Kế toán, Ban quản trị, Kiểm soát có sẵn) và tick quyền cho từng nhóm. Mỗi nhân viên thuộc một nhóm; đổi quyền nhóm có hiệu lực ngay. Nhóm không có quyền "Xem quỹ" (ví dụ Kỹ thuật) không thấy tab Thu chi, số dư quỹ, báo cáo quỹ; máy chủ cũng không gửi dữ liệu thu chi cho họ.
- **Tài khoản người dùng**: loại Trưởng ban, Nhân viên (chọn nhóm) hoặc Cư dân (gắn số căn). Tài khoản cũ (Kế toán, Ban quản lý, Ban quản trị, Ban kiểm tra) được tự chuyển vào nhóm tương ứng lần đầu sau khi cập nhật.
- **Quy tắc mã**: mã tự cấp, không sửa tay. Mặc định: nhóm `NH-KT`, người dùng `KT-001` (mã nhóm + số), tài sản `TS-0001`, bảo trì `BT-2026-0001`, phiếu thu `PT-2026-0001`, phiếu chi `PC-2026-0001`, nhà thầu `NT-001`. Trưởng ban đổi tiền tố, có năm hay không, số chữ số; áp dụng cho hồ sơ tạo sau đó. Nút "Đánh mã bù" cấp mã cho hồ sơ cũ còn thiếu (lần đầu cập nhật hệ thống tự làm).

## Công việc bảo trì và dự toán quỹ

- **Công việc** (tab mới, nhân viên thấy): phiếu công việc giao cho một nhóm, đi theo bước Đề xuất, Duyệt, Thực hiện, Chờ nghiệm thu, Hoàn thành (hoặc Từ chối, Hủy). Phiếu có dự toán từ mức duyệt chi lớn trở lên phải do Trưởng ban hoặc Ban quản trị duyệt, người tạo không tự duyệt. Khi nghiệm thu, hệ thống tự ghi một lần bảo trì vào Nhật ký của tài sản, kèm chi phí thực tế. Mọi bước được lưu lịch sử và nhật ký. Nút "Tạo phiếu việc" có ngay ở Lịch tới hạn.
- **Ghi chi vào quỹ**: người có quyền ghi thu chi tích "Ghi luôn khoản chi vào Quỹ bảo trì" khi nghiệm thu. Nếu không, tab Thu chi có mục "Chi phí bảo trì chưa ghi vào quỹ" với nút "Ghi chi" cho kế toán.
- **Dự toán** (Báo cáo, chọn "Dự toán và thực hiện"): Trưởng ban hoặc Ban quản trị lập dự toán năm theo hạng mục thu, chi của từng quỹ; bảng so sánh với thực hiện, còn lại và tỷ lệ. Hạng mục chi dùng từ 90% dự toán sẽ báo ở Tổng quan. Nhóm không có quyền xem quỹ không thấy dự toán.

Quyền "Hiện menu Căn hộ, Gửi xe, Biểu quyết, Tiện ích" chỉ ẩn hiện menu; việc được làm trong các mục đó vẫn theo quyền Nhập liệu, Biểu quyết, Tiện ích. Quyền được kiểm tra ở máy chủ (`$CAPS` trong api.php), giao diện chỉ ẩn nút cho gọn.

## Bảo mật tài khoản và sao lưu

- Tài khoản mới tạo, hoặc được Trưởng ban đặt lại mật khẩu, bị buộc đổi mật khẩu ở lần đăng nhập đầu. Mật khẩu mới phải khác mật khẩu cấp.
- Xác thực 2 bước (TOTP) tùy chọn cho mọi tài khoản riêng: nút "Bảo mật 2 bước" trên đầu trang, dùng Google Authenticator, Microsoft Authenticator, Authy (nhập khóa thủ công). Mỗi mã chỉ dùng được một lần. Trưởng ban tắt giúp khi người dùng mất điện thoại (Quản trị, Sửa tài khoản).
- Tự sao lưu cơ sở dữ liệu mỗi ngày vào thư mục `backups` cạnh tệp dữ liệu (ngoài public_html), giữ 14 bản gần nhất; Trưởng ban tải ở tab Quản trị. Tệp chứng từ đính kèm vẫn tải thủ công (.zip).

## Mã QR tài sản và đối chiếu sao kê

**Mã QR tài sản.** Ở tab Tài sản, bấm “QR” để xem và in tem của một tài sản, hoặc “In tem QR tất cả” để in cả lưới tem (mã, tên, QR). Quét bằng điện thoại sẽ mở `#ts-<id>`: sau khi đăng nhập, hệ thống mở thẳng hồ sơ tài sản, gồm lịch sử bảo trì, chứng từ và bình luận. Mã QR được tạo ngay trên trình duyệt, không gọi dịch vụ ngoài. Mã chứa địa chỉ trang, không chứa dữ liệu, nên không lộ thông tin cho người chưa đăng nhập.

**Đối chiếu sao kê ngân hàng** (tab Đối chiếu, cần quyền Thu chi, ví dụ nhóm Quản lý chung và Kế toán). Cuối tháng:
1. Bấm “Nhập sao kê”, chọn quỹ, tải tệp CSV hoặc dán bảng copy từ Excel. Hệ thống tự nhận cột ngày, diễn giải, ghi nợ, ghi có (hoặc một cột số tiền, số âm là chi); có bước kiểm tra lại cột.
2. Dòng sao kê được tự khớp với khoản thu chi cùng quỹ, cùng chiều, cùng số tiền, lệch tối đa 5 ngày. Dòng chưa khớp có thể chọn khoản trong sổ, ghi thẳng vào sổ thu chi, hoặc bỏ qua (ví dụ lãi tiền gửi nhỏ).
3. Nhập số dư cuối tháng trên sao kê để xem chênh lệch với số dư theo sổ. Danh sách “khoản trong sổ chưa có trên sao kê” giúp tìm séc chưa qua ngân hàng hoặc khoản ghi nhầm.

Sao kê nhập lại nhiều lần không bị trùng dòng. Sao kê lưu theo quỹ và tháng, ghi nhật ký thao tác, tối đa 500 dòng mỗi tháng.
