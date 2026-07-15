# Changelog — Vinasite Google Indexing

## [1.2] — 2026-07
- **Tự cập nhật từ GitHub**: nhúng thư viện Plugin Update Checker v5.7 + header `Update URI`. Từ nay nâng cấp tính năng ở repo này thì mọi site đang cài đều thấy nút "Cập nhật" trong Plugins, không cần cài lại tay từng máy.
- Thêm `Plugin URI`, `Author URI`, `Requires at least`, `Requires PHP` vào header.

## [1.1] — 2026-07
- Quét index hàng ngày bằng **URL Inspection API**: mỗi ngày hỏi Google từng URL "đã index chưa?", chỉ bài CHƯA index mới gửi lên Indexing API. Bài đã index kiểm lại sau 30 ngày, chưa index sau 3 ngày.
- Ngân sách thời gian 90s/lượt để không treo cron; token cache riêng theo scope.

## [1.0] — 2026-07
- Bản đầu: gửi URL lên Indexing API bằng service account khi publish, bulk submit, log, retry chống timeout.
