# Vinasite Google Indexing

Plugin WordPress của **Công ty TNHH VinaSite Việt Nam** — gửi URL lên Google Indexing API bằng service account.

## Chức năng
- Tự gửi URL lên Indexing API khi publish / cập nhật bài.
- **Quét index hàng ngày** bằng URL Inspection API: mỗi ngày hỏi Google từng URL "đã index chưa?", **chỉ gửi bài CHƯA được index**. Bài đã index kiểm lại sau 30 ngày, chưa index sau 3 ngày.
- Gửi hàng loạt (bulk submit), ghi log, retry chống timeout.
- Ngân sách thời gian 90 giây mỗi lượt cron để không treo server.

## Cài đặt
Plugin được **đóng gói sẵn trong theme VinaSite** — kích hoạt theme là tự cài và bật. Muốn cài tay thì tải zip từ repo này rồi upload trong **Plugins → Cài mới → Tải plugin lên**.

## Tự cập nhật
Plugin tự kiểm tra nhánh `main` của repo này (qua thư viện Plugin Update Checker nhúng sẵn) và hiện nút **Cập nhật** trong trang Plugins như plugin trên wordpress.org. Không cần cài thêm plugin nào.

## Cấu hình
Vào **Cài đặt → Google Indexing**:
1. Dán **service account JSON** (tạo ở Google Cloud Console, bật Indexing API + Search Console API).
2. Thêm `client_email` của service account làm **người dùng** của property trong Google Search Console — thiếu bước này sẽ báo lỗi `forbidden`.
3. Nhập URL property (vd `https://vidu.com/`), chọn loại nội dung, bật quét hàng ngày.

Service account JSON **lưu trong database của từng site**, không nằm trong mã nguồn.

## Hạn mức Google
- Indexing API: **200 URL/ngày**
- URL Inspection API: **2.000 URL/ngày**

Chỉnh số lượng gửi/kiểm tra mỗi ngày trong trang cài đặt.

## Giấy phép
GPLv2 or later.
