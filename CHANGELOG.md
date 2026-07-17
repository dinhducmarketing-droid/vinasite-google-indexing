# Changelog — Vinasite Google Indexing

## [1.3] — 2026-07
- **Thêm chế độ "Gửi thẳng" hàng ngày** — mỗi ngày (3:00 sáng) tự gửi bài mới nhất thẳng lên Indexing API, KHÔNG cần hỏi Search Console. Chạy được ngay chỉ với quyền Indexing API. Trước đây chỉ có kiểu "Thông minh" (hỏi đã index chưa rồi mới gửi) — kiểu này cần cấp quyền Search Console cho service account; site nào chưa cấu hình thì quét bị kẹt, không gửi được gì.
- Chọn kiểu chạy ở mục 3 trang cài đặt: **Gửi thẳng** (không cần GSC) hoặc **Thông minh** (tiết kiệm quota, cần GSC). Mặc định giữ **Thông minh** khi cập nhật để không đổi hành vi site đang chạy; site tự chuyển sang Gửi thẳng nếu muốn.
- Chế độ Gửi thẳng: ưu tiên bài chưa gửi bao giờ → rồi bài gửi lâu nhất; bài đã gửi trong vòng N ngày (mặc định 10) thì bỏ qua để khỏi gửi lại URL không đổi, đỡ tốn quota Indexing (200/ngày).
- Kiểm chứng thật trên vanphongluatsu.com.vn: gửi 3 URL đều `OK / URL_UPDATED`, lượt sau tự chọn URL khác (không gửi trùng).

## [1.2] — 2026-07
- **Tự cập nhật từ GitHub**: nhúng thư viện Plugin Update Checker v5.7 + header `Update URI`. Từ nay nâng cấp tính năng ở repo này thì mọi site đang cài đều thấy nút "Cập nhật" trong Plugins, không cần cài lại tay từng máy.
- Thêm `Plugin URI`, `Author URI`, `Requires at least`, `Requires PHP` vào header.

## [1.1] — 2026-07
- Quét index hàng ngày bằng **URL Inspection API**: mỗi ngày hỏi Google từng URL "đã index chưa?", chỉ bài CHƯA index mới gửi lên Indexing API. Bài đã index kiểm lại sau 30 ngày, chưa index sau 3 ngày.
- Ngân sách thời gian 90s/lượt để không treo cron; token cache riêng theo scope.

## [1.0] — 2026-07
- Bản đầu: gửi URL lên Indexing API bằng service account khi publish, bulk submit, log, retry chống timeout.
