# Baseline an toàn triển khai HPU2

Phiên bản 2.0 áp dụng các kiểm soát nền theo OWASP ASVS 5.0 mức 2: xác thực phía máy chủ, phân quyền, CSRF, prepared statements, escaping, CSP, phiên strict, giới hạn đăng nhập, nhật ký quản trị và trung hòa công thức CSV.

## Trước khi công khai Internet

- Dùng Linux/Apache hoặc Nginx được vá; PHP 8.4/8.5 bản vá mới nhất. Không dùng XAMPP làm máy chủ Internet lâu dài.
- Bắt buộc HTTPS; chuyển hướng toàn bộ HTTP sang HTTPS; chỉ bật `trusted_proxy_https` khi reverse proxy do đơn vị kiểm soát.
- Tạo database user riêng, chỉ cấp quyền cho đúng database; đặt mật khẩu qua biến môi trường.
- Tắt tự đăng ký. Ưu tiên SSO HPU2; nếu chưa có, quản trị viên tạo/duyệt tài khoản sau xác minh độc lập.
- Bảo vệ tài khoản quản trị bằng MFA/SSO, VPN hoặc giới hạn IP; không dùng chung tài khoản.
- Sau cài đặt/nâng cấp, đổi tên hoặc xóa `install.php` và `upgrade.php`.
- Thiết lập sao lưu mã hóa, thử khôi phục, giám sát log, cảnh báo đăng nhập bất thường và quy trình ứng cứu.
- Kiểm thử xâm nhập trước nghiệm thu và sau thay đổi lớn; rà soát quyền truy cập, học liệu và bản vá tối thiểu mỗi quý.

## Dữ liệu và AI

Không đưa bí mật nhà nước, thông tin mật của đơn vị, dữ liệu cá nhân nhạy cảm, hồ sơ người học hoặc dữ liệu chưa được phép xử lý vào Gemini hay công cụ AI công khai. AI chỉ hỗ trợ dự thảo; người có thẩm quyền phải kiểm tra căn cứ, số liệu, thể thức, thẩm quyền và chịu trách nhiệm về văn bản cuối cùng.

Tham chiếu nội dung: Luật Bảo vệ dữ liệu cá nhân 91/2025/QH15; Nghị định 356/2025/NĐ-CP; Nghị định 30/2020/NĐ-CP; Thông tư 02/2025/TT-BGDĐT. Ngày rà soát: 24/09/2026.
