Bắt buộc phải tuân thủ 2 quy định:

- Sau mỗi lần thay đổi đều phải tạo 1 commit git tương ứng, để sau này còn theo dõi và khôi phục
- Sau mỗi lần thay đổi đều phải viết hoặc cập nhật các bài test liên quan và trước khi giao cho người dùng phải đảm bảo tất cả các bài test và nghiệm thu đều đạt

## Quy trình nhánh (branch)

Nhánh `main` luôn phải ở trạng thái chạy được và đã qua test. Không push trực tiếp lên `main`.

1. Cập nhật `main`, rồi tạo nhánh riêng cho mỗi thay đổi: `git switch main`, `git pull`, `git switch -c feature/ten-tinh-nang` (hoặc `fix/ten-loi`, `docs/ten-tai-lieu`).
2. Làm việc, commit và chạy test ngay trên nhánh đó (xem mục "Cách chạy test").
3. Đẩy nhánh lên GitHub: `git push -u origin feature/ten-tinh-nang`.
4. Mở Pull Request vào `main`, mô tả ngắn thay đổi và kết quả test. Chỉ gộp (merge) khi test đạt và đã có người xem lại.
5. Sau khi gộp, xóa nhánh và cập nhật lại `main` trên máy: `git switch main`, `git pull`.

## Cách chạy test

Chạy trong thư mục dự án (`hpu2-xampp`), dùng PHP của XAMPP, không cần cài thêm thư viện:

```
C:\xampp\php\php.exe tests\run.php              # chạy tất cả (unit + data + acceptance)
C:\xampp\php\php.exe tests\run.php unit         # chỉ test đơn vị các hàm trong app.php
C:\xampp\php\php.exe tests\run.php data         # chỉ test toàn vẹn seed.json / paths.json / schema.sql
C:\xampp\php\php.exe tests\run.php acceptance   # chỉ test nghiệm thu qua HTTP
```

- Phần `acceptance` cần **MySQL của XAMPP đang chạy**. Nó tự mở web server thử nghiệm ở cổng 8089 và dùng CSDL riêng `hpu2_test_acceptance` (tự tạo, tự xóa), không đụng vào CSDL thật. Tên CSDL được đổi qua biến môi trường `HPU2_DB_NAME` (xem `config.php`).
- Phần `acceptance` không kiểm tra `.htaccess` vì web server thử nghiệm không đọc tệp này (chỉ Apache đọc).
- Lệnh kết thúc với mã thoát 0 khi mọi test đạt, khác 0 nếu có test lỗi.
- Kiểm thử tải: `tests/load.js` (k6), xem `PERFORMANCE.md`.

## Khi thêm hoặc sửa chức năng

- Hàm thuần trong `app.php`: thêm test vào `tests/unit.php`.
- Thay đổi học liệu (`database/*.json`, `schema.sql`): cập nhật `tests/data.php`.
- Thay đổi luồng người dùng (cài đặt, đăng nhập, quiz, video, quản trị): thêm bước vào `tests/acceptance.php`.
