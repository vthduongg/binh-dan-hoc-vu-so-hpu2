# Bình dân học vụ số HPU2 — bản XAMPP 2.1

Website học tập PHP + MySQL/MariaDB chạy độc lập trên XAMPP, không cần Node.js.

## Yêu cầu

- XAMPP có PHP 8.2 trở lên (khuyến nghị PHP 8.4/8.5 còn được hỗ trợ) và MySQL/MariaDB.
- Bật hai dịch vụ **Apache** và **MySQL** trong XAMPP Control Panel.

## Cài đặt trong 5 bước

1. Giải nén thư mục vào `C:\xampp\htdocs\binh-dan-hoc-vu-so`.
2. Cấu hình MySQL trong `config.php`. Chỉ dùng `root`/mật khẩu trống trên máy phát triển; máy chủ thật phải dùng tài khoản database riêng hoặc biến môi trường `HPU2_DB_USER`, `HPU2_DB_PASS`.
3. Truy cập `http://localhost/binh-dan-hoc-vu-so/install.php`.
4. Tạo tài khoản quản trị, giữ dấu chọn nhập dữ liệu mẫu, rồi đăng nhập.
5. Kiểm tra trang học viên và bảng quản trị trên cả máy tính lẫn điện thoại.

Sau khi cài đặt thành công, đổi tên hoặc xóa `install.php` để giảm bề mặt tấn công.

## Nâng cấp từ bản 1.x hoặc 2.0

1. Sao lưu database và thư mục website.
2. Chép đè mã nguồn bản 2.1, giữ lại cấu hình kết nối database.
3. Đăng nhập quản trị, mở `upgrade.php`, bấm **Thực hiện nâng cấp** đúng một lần.
4. Đổi tên hoặc xóa `upgrade.php` sau khi hoàn tất.

## Quyền quản trị

Tài khoản vai trò **Quản trị** có thể:

- Thêm, sửa, ẩn/hiện và xóa khóa học.
- Thêm, sửa, sắp xếp và xóa bài giảng.
- Soạn nội dung chính, mục tiêu, tình huống và bài thực hành.
- Thêm/sửa/xóa quiz, phương án trả lời và đáp án đúng.
- Quản lý tài khoản, khóa người dùng, cấp hoặc thu hồi quyền quản trị.
- Xem tiến độ tập trung, điểm tốt nhất, hoạt động gần nhất và xuất CSV mở bằng Excel.
- Thay đổi thông báo, điểm đạt quiz và chế độ tự đăng ký.
- Giới hạn tài khoản tự đăng ký theo miền email HPU2 (mặc định `hpu2.edu.vn`).
- Gắn video YouTube cho từng bài, đặt tỷ lệ xem bắt buộc và theo dõi checkpoint.
- Quản lý nguồn chính thức, ngày rà soát học liệu và nhật ký thao tác quản trị.
- Thêm, sửa, sắp xếp, ẩn/hiện lộ trình học và chọn các khóa học trong từng lộ trình.

Mặc định tự đăng ký **tắt**. Nếu bật, tài khoản mới ở trạng thái khóa và phải được quản trị viên xác minh/kích hoạt. Kiểm tra đuôi `@hpu2.edu.vn` không thay thế xác minh email hoặc SSO.

## Luồng người học

- Người học đăng ký hoặc được quản trị viên quản lý tài khoản.
- Bài đầu tiên của mỗi khóa được mở sẵn.
- Người học phải đạt mức điểm do quản trị cấu hình (mặc định 70%) để hoàn thành bài và mở bài tiếp theo.
- Mọi lần làm quiz và điểm tốt nhất được lưu trong cơ sở dữ liệu.
- Bài có video có thể yêu cầu người học xác nhận đủ tỷ lệ xem trước khi nộp quiz.
- Người học chọn một trong 6 lộ trình hoặc làm đánh giá đầu vào theo 6 miền năng lực số.

## Đưa lên hosting thật

- Upload toàn bộ thư mục lên hosting có PHP 8.2+ và MySQL/MariaDB.
- Tạo database và user database trong cPanel/DirectAdmin, sau đó sửa `config.php`.
- Đặt `base_url` theo đường dẫn triển khai; để trống nếu website nằm ở thư mục gốc của tên miền.
- Bật HTTPS và sử dụng mật khẩu MySQL riêng, không dùng tài khoản `root` trên hosting thật.
- Sao lưu định kỳ database và thư mục website.
- Bật MFA/SSO cho quản trị nếu hạ tầng HPU2 hỗ trợ; giới hạn truy cập `/admin.php` theo VPN/IP khi có thể.
- XAMPP phù hợp máy cá nhân/mạng nội bộ thử nghiệm, không phải cấu hình máy chủ công khai khuyến nghị.
- Khi chạy nhiều máy chủ ứng dụng, cài Redis và đặt `HPU2_REDIS_DSN` để chia sẻ phiên đăng nhập. Xem `PERFORMANCE.md` và chạy `tests/load.js` trước khi công bố sức chứa.

## Bảo mật đã tích hợp

- PDO prepared statements, escaping đầu ra, CSRF, mật khẩu băm, kiểm soát vai trò.
- Giới hạn 8 lần đăng nhập thất bại trong 15 phút theo email/IP; phiên strict, hết hạn nhàn rỗi và tuyệt đối.
- CSP, HSTS khi HTTPS, chống nhúng chéo, hạn chế quyền trình duyệt; xuất CSV được trung hòa công thức.
- Máy chủ kiểm tra lại thứ tự mở khóa, trạng thái video và quiz; bài không có câu hỏi không thể tự hoàn thành.
- Nhật ký các thao tác quan trọng. Đây là lớp nền; khi công khai Internet vẫn cần TLS, WAF, giám sát, vá định kỳ và kiểm thử xâm nhập.

## Chuẩn nội dung

Học liệu mẫu được rà soát ngày 24/09/2026 và dẫn nguồn chính thức. Trọng tâm gồm Thông tư 02/2025/TT-BGDĐT về Khung năng lực số cho người học; Thông tư 18/2026/TT-BGDĐT về Khung năng lực số đối với giáo viên, cán bộ quản lý cơ sở giáo dục; Nghị định 30/2020/NĐ-CP về công tác văn thư; Luật Bảo vệ dữ liệu cá nhân 91/2025/QH15 và Nghị định 356/2025/NĐ-CP. Quản trị viên chịu trách nhiệm phê duyệt nghiệp vụ trước khi xuất bản và rà soát tối thiểu mỗi quý.

## Cấu trúc chính

- `index.php`: giao diện học viên, đăng nhập, khóa học, bài giảng và quiz.
- `admin.php`: toàn bộ bảng quản trị.
- `app.php`: kết nối dữ liệu, phiên đăng nhập và hàm dùng chung.
- `config.php`: cấu hình website và cơ sở dữ liệu.
- `database/schema.sql`: cấu trúc dữ liệu.
- `database/seed.json`: 12 khóa học mẫu.
- `database/paths.json`: 6 lộ trình, 6 miền năng lực và câu hỏi đánh giá đầu vào.
- `PERFORMANCE.md`, `tests/load.js`: hướng dẫn vận hành và kịch bản đo tải k6.
- `assets/`: giao diện responsive và JavaScript.
