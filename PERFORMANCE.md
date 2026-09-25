# Vận hành và tải lớn

## Mức triển khai

- **Thử nghiệm nội bộ:** XAMPP, một máy chủ, tối đa khoảng 50–100 người truy cập đồng thời sau khi đo tải.
- **Triển khai toàn trường:** Linux + Nginx/Apache, PHP-FPM OPcache, MySQL/MariaDB riêng, HTTPS, sao lưu tự động.
- **Tải lớn hoặc nhiều cơ sở:** ít nhất hai máy chủ ứng dụng sau bộ cân bằng tải; phiên đăng nhập dùng Redis qua biến `HPU2_REDIS_DSN`; cơ sở dữ liệu được giám sát và có bản sao dự phòng.

Không cam kết số người dùng chỉ từ cấu hình lý thuyết. Hãy chạy kịch bản `tests/load.js` trên môi trường gần giống máy chủ thật và tăng tải theo từng bước 50 → 200 → 500 → 1.000 người dùng.

## Chỉ số nên theo dõi

- p95 thời gian phản hồi dưới 1,5 giây với trang đọc; tỷ lệ lỗi dưới 1%.
- CPU ứng dụng dưới 70%, kết nối cơ sở dữ liệu còn dự phòng tối thiểu 25%.
- Truy vấn chậm, dung lượng bảng `progress`, `quiz_attempts`, `audit_logs`, `login_attempts`.
- Nhật ký lỗi không chứa mật khẩu, mã phiên, nội dung trả lời hoặc dữ liệu cá nhân không cần thiết.

## Việc định kỳ

1. Sao lưu và thử khôi phục cơ sở dữ liệu.
2. Xóa nhật ký theo thời hạn lưu đã phê duyệt; không xóa tiến độ nếu chưa có quyết định nghiệp vụ.
3. Cập nhật PHP, Apache/Nginx, MySQL/MariaDB và Redis theo bản vá an toàn.
4. Chạy lại kiểm thử tải sau mỗi thay đổi lớn về học liệu, báo cáo hoặc hạ tầng.
