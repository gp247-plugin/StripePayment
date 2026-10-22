# Plugin StripePayment

> 🌐 **Ngôn ngữ:** 🇻🇳 Tiếng Việt (hiện tại) · [🇬🇧 English](./readme.md) · [🇯🇵 日本語](./readme_ja.md)

Nhận thanh toán qua **Stripe** cho cửa hàng GP247/Shop: thẻ quốc tế, Apple Pay, Google Pay, Link và các phương thức
địa phương bạn bật trên tài khoản Stripe. Khách thanh toán trên trang bảo mật của Stripe rồi quay về cửa hàng — thông
tin thẻ không bao giờ đi qua website của bạn.

---

## Tổng quan

| | |
| --- | --- |
| **Plugin** | StripePayment |
| **Phiên bản** | 1.1.2 |
| **Nhà phát triển** | GP247 |
| **Giấy phép** | Miễn phí |
| **Yêu cầu** | GP247 Core **3.1+** (cấu hình theo cửa hàng, mã hoá khoá bí mật, yêu cầu thanh toán) · package `gp247/shop` |
| **Không cần** | Composer package mới, cron, queue |

## Tính năng

- 💳 **Stripe Checkout** — trang thanh toán do Stripe lưu trữ, tự có 3-D Secure và ví điện tử.
- 🧾 **Ghi tiền đúng vào đơn** — số tiền Stripe thực thu được ghi vào sổ thanh toán của đơn, đúng một lần, kể cả khi
  khách đóng trình duyệt trước khi về trang cảm ơn (nhờ webhook).
- ↩️ **Đồng bộ hoàn tiền** — hoàn tiền một phần hoặc toàn phần trên Stripe Dashboard được ghi vào đơn.
- 🧪 **Test & Live** — một công tắc chuyển giữa khoá thử nghiệm và khoá thật.
- 🏬 **Theo từng cửa hàng** — mỗi cửa hàng có thể dùng tài khoản Stripe riêng, hoặc dùng chung tài khoản của site/sàn.
- 🔒 **An toàn** — webhook được xác minh chữ ký; secret key và webhook secret được **mã hoá** trong CSDL; chỉ chủ
  site/chủ sàn cấu hình được.
- 💱 **Đúng đơn vị tiền tệ** — VND, JPY và các loại tiền "không số lẻ" được gửi đúng quy tắc của Stripe.

| Tình huống | Plugin làm gì |
| --- | --- |
| Khách thanh toán thành công | Ghi khoản thu vào đơn, chuyển đơn sang trạng thái bạn chọn (mặc định *Đang xử lý*) |
| Khách bấm quay lại trên trang Stripe | Huỷ đơn và trả hàng về kho |
| Khách bỏ dở, đóng trình duyệt | Đơn giữ ở trạng thái chờ — bạn quyết định huỷ hay liên hệ khách |
| Thanh toán chậm (chuyển khoản, ghi nợ ngân hàng) | Đơn được đặt, tiền ghi khi Stripe báo thành công; thất bại thì ghi chú vào lịch sử đơn |
| Hoàn tiền trên Stripe | Ghi khoản hoàn; hoàn hết mới chuyển đơn sang *Đã hoàn tiền* |
| Số tiền Stripe thu khác tổng đơn | Ghi đúng số thực thu, ghi chú lệch, **không** đánh dấu đã thanh toán |

---

## Link thanh toán (Yêu cầu thanh toán)

Từ **1.1.0**, với `gp247/shop` có màn **Yêu cầu thanh toán**, Stripe còn thu được các khoản **ngoài giỏ hàng** — trả nốt đơn, công nợ, đặt cọc, hoá đơn tự do:

1. Admin tạo yêu cầu thu trong **Yêu cầu thanh toán**, bấm **Tạo link thanh toán** và gửi link cho khách.
2. Khách mở link, chọn **Stripe**, trả trên trang Stripe; Stripe đưa khách về lại trang link với thông báo đã nhận tiền.
3. Tiền được ghi vào yêu cầu **đúng một lần** (webhook và trang quay về có thể cùng tới).
4. Hoàn tiền: trên dòng tiền đã thu, bấm **Hoàn qua cổng** (cần quyền chi tiền). Hoàn làm trực tiếp trên Stripe Dashboard cũng được ghi về yêu cầu.

Không cần cấu hình thêm: dùng cùng khoá và cùng webhook (các sự kiện đã đăng ký ở mục dưới). Stripe chỉ hiện trên trang link khi **cửa hàng sở hữu yêu cầu** đã có secret key — tiền luôn vào đúng tài khoản Stripe của cửa hàng đó.

## Cài đặt

1. Chép thư mục `StripePayment` vào `app/GP247/Plugins/` trên website (hoặc tải file ZIP lên ở
   **Admin → Extensions / Plugins → Import**).
2. Vào **Admin → Extensions / Plugins**, tìm **Stripe** và bấm **Install**.

Plugin được bật sau khi cài, nhưng chỉ nhận tiền được sau khi bạn nhập khoá Stripe (mục dưới).

---

## Cấu hình

Mở **Admin → Plugins → Stripe** (hoặc bấm vào plugin trong danh sách). Màn cấu hình chia **4 khối** để khoá thử
nghiệm và khoá thật không bao giờ nằm lẫn nhau:

| Khối | Ô | Nhập gì |
| --- | --- | --- |
| **Chế độ** | Chế độ thử nghiệm | Bật = dùng khối **Sandbox** (không trừ tiền thật) · Tắt = dùng khối **Live**. Khối đang dùng có nhãn **Đang dùng**. Dòng mô tả của khối này ghi sẵn **URL webhook** và các sự kiện cần chọn |
| **Sandbox (thử nghiệm)** | Secret key | Khoá `rk_test_…` (khuyên dùng) hoặc `sk_test_…` — xem [Tạo khoá Stripe](#tạo-khoá-stripe) |
| | Webhook signing secret | `whsec_…` của endpoint webhook tạo **trong sandbox** |
| **Live (thật)** | Secret key | Khoá `rk_live_…` (khuyên dùng) hoặc `sk_live_…` |
| | Webhook signing secret | `whsec_…` của endpoint webhook tạo **ở chế độ live** |
| **Trạng thái đơn hàng** | Sau khi thanh toán · Khi hoàn tiền toàn bộ | Mặc định *Processing* · *Refunded* |

Khoá bí mật được **mã hoá** khi lưu và **không bao giờ hiện lại** trên màn hình — dưới mỗi ô chỉ có nhãn *Đã lưu* hoặc
*Chưa đặt*. Để trống ô bí mật khi bấm **Lưu** nghĩa là *giữ nguyên* giá trị cũ; muốn đổi thì dán giá trị mới.

---

## Tạo khoá Stripe

Bạn cần **2 thứ cho mỗi môi trường**: một **API key** (để tạo phiên thanh toán) và một **webhook signing secret** (để
xác minh thông báo Stripe gửi về). Sandbox và Live là **hai bộ riêng** — khoá của môi trường này không dùng được ở
môi trường kia.

| Môi trường Stripe | Khối trên màn cấu hình | API key bắt đầu bằng | Webhook secret |
| --- | --- | --- | --- |
| **Sandbox** (thử nghiệm, không trừ tiền thật) | Sandbox (thử nghiệm) | `rk_test_` hoặc `sk_test_` | `whsec_…` của endpoint trong sandbox |
| **Live** (tiền thật) | Live (thật) | `rk_live_` hoặc `sk_live_` | `whsec_…` của endpoint ở chế độ live |

> Nên làm trọn **Sandbox** trước, thử thanh toán thành công, rồi mới làm **Live**. Để dùng Live, tài khoản Stripe
> phải được kích hoạt (khai thông tin doanh nghiệp và tài khoản ngân hàng nhận tiền).

### Bước 1 — Chọn đúng môi trường trên Stripe

1. Đăng nhập [dashboard.stripe.com](https://dashboard.stripe.com).
2. Ở bộ chọn tài khoản (góc trên bên trái), chọn **Sandboxes** rồi mở một sandbox (tạo mới nếu chưa có) — hoặc giữ
   tài khoản chính để làm **Live**.
3. Mọi bước dưới đây làm **trong môi trường vừa chọn**. Làm xong Sandbox thì quay lại bước này để làm Live.

### Bước 2 — Tạo API key

**Cách khuyên dùng: restricted key** (chỉ cấp đúng quyền plugin cần — nếu lộ, kẻ gian cũng không làm được gì khác).

1. Mở trang **API keys** ([dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys)).
2. Bấm **Create restricted key**.
3. **Key name**: đặt tên dễ nhận, ví dụ `GP247 StripePayment – ten-mien-cua-ban`.
4. Để mọi quyền là **None**, **trừ** **Checkout Sessions** → chọn **Write**. Plugin chỉ tạo và đọc phiên thanh toán,
   không cần quyền nào khác.
5. Bấm **Create key**, nhập mã xác minh Stripe gửi qua email/SMS.
6. **Copy khoá ngay** (bấm vào giá trị khoá). Ở chế độ **Live**, khoá tự tạo **chỉ hiện một lần** — lỡ đóng mà chưa
   copy thì phải tạo khoá khác.
7. Dán vào ô **Secret key** của **đúng khối**: khoá `rk_test_…` → khối **Sandbox**; khoá `rk_live_…` → khối **Live**.

**Cách nhanh: secret key chuẩn** (`sk_…`, toàn quyền trên tài khoản — kém an toàn hơn): ở trang **API keys**, mục
**Standard keys**, bấm **Reveal** ở dòng *Secret key* rồi copy. Trong sandbox luôn xem lại được; ở Live chỉ xem lại được
khoá do Stripe tạo sẵn.

### Bước 3 — Tạo webhook và lấy signing secret

Không có webhook, tiền chỉ được ghi khi khách quay về trang cảm ơn, và hoàn tiền trên Stripe không về đơn.

1. Trên màn cấu hình Stripe của GP247, copy **URL webhook** ở dòng mô tả của khối **Chế độ**, dạng
   `https://ten-mien-cua-ban/plugin/stripe-payment/webhook`.
2. Trên Stripe, mở **Workbench → Webhooks** ([dashboard.stripe.com/webhooks](https://dashboard.stripe.com/webhooks))
   → **Create an event destination**.
3. Chọn nguồn sự kiện **Your account**; phiên bản API để mặc định.
4. Chọn đúng 6 sự kiện: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`, `refund.created`, `refund.updated`, `refund.failed`.
5. Chọn loại đích **Webhook endpoint**, dán URL ở bước 1 vào **Endpoint URL**, rồi tạo.
6. Trên trang của endpoint vừa tạo, bấm **Reveal** ở mục **Signing secret** và copy giá trị `whsec_…`.
7. Dán vào ô **Webhook signing secret** của **cùng khối** với API key ở Bước 2 (Sandbox hoặc Live), rồi bấm **Lưu**.

Webhook cần địa chỉ **HTTPS công khai**. Khi thử trên máy cá nhân (chưa có HTTPS), dùng
[Stripe CLI](https://docs.stripe.com/stripe-cli):
`stripe listen --forward-to http://localhost/plugin/stripe-payment/webhook` — lệnh in ra một secret `whsec_…`, dán
secret đó vào khối **Sandbox**.

### Tự kiểm tra trước khi lưu

- Khối **Sandbox** chỉ chứa khoá có chữ `_test_`; khối **Live** chỉ chứa khoá có chữ `_live_`.
- API key và webhook secret trong **cùng khối** phải lấy từ **cùng môi trường** Stripe.
- Công tắc **Chế độ thử nghiệm** khớp với khối bạn muốn dùng (nhãn **Đang dùng** hiện ở khối đó sau khi **Lưu**).

### Giữ khoá an toàn

- Không gửi khoá qua email, chat hay ảnh chụp màn hình. Chỉ dán thẳng vào màn cấu hình.
- Nghi ngờ khoá bị lộ: trên Stripe, trang **API keys** → nút **⋯** của khoá → **Rotate key**, rồi dán khoá mới vào
  GP247. Webhook secret cũng có thể **Roll secret** tương tự trên trang endpoint.

### Nhiều cửa hàng / sàn

- **Multi-store**: chọn cửa hàng ở đầu màn cấu hình rồi nhập khoá Stripe riêng. Mỗi tên miền khai báo một endpoint
  webhook riêng và **mỗi endpoint có signing secret riêng** — nhập secret đó ở đúng cửa hàng, kể cả khi các cửa hàng
  dùng chung một tài khoản Stripe.
- **Sàn (marketplace)**: nhập khoá ở mức chung; cửa hàng con để trống sẽ dùng tài khoản của sàn.
- Bật/tắt Stripe cho từng cửa hàng ở màn **Quản lý Plugin**.
- Quản trị viên cửa hàng con và vendor không mở được màn cấu hình này.

---

## Thử thanh toán

Bật **Chế độ thử nghiệm**, đặt hàng và chọn Stripe, dùng thẻ thử `4242 4242 4242 4242`, ngày hết hạn bất kỳ trong
tương lai, CVC bất kỳ. Xem thêm thẻ thử tại tài liệu Stripe (*Testing*).

## Lưu ý

- **Tiền tệ**: tiền tệ của đơn phải được tài khoản Stripe hỗ trợ và đạt mức tối thiểu của Stripe (ví dụ 0.50 USD,
  50 JPY). Nếu Stripe từ chối, đơn được huỷ, hàng trả về kho và khách được mời chọn cách thanh toán khác.
- Stripe hiển thị **một dòng** "Đơn hàng #…" bằng đúng tổng đơn (danh sách sản phẩm nằm trong phần mô tả), để số tiền
  luôn khớp với đơn kể cả khi có giảm giá, thuế, phí vận chuyển.
- **Hoàn tiền** làm trên Stripe Dashboard. Nếu Stripe báo hoàn tiền thất bại, đơn được ghi chú để bạn xử lý tay.
- Gỡ plugin chỉ xoá cấu hình của plugin; các khoản thu/hoàn đã ghi vào đơn vẫn giữ nguyên.

## Lịch sử phiên bản

- **1.1.2** — Thêm bản dịch tiếng Nhật (日本語) cho các màn của plugin.
- **1.1.1** — Màn cấu hình có khung **Kiểm tra trước khi dùng**: những gì còn khiến khách chưa trả được bằng Stripe (bước thanh toán đang tắt ở checkout, Stripe chưa bật cho cửa hàng, thiếu Secret key hoặc Webhook signing secret của chế độ đang dùng, URL webhook Stripe không gọi tới được) và lời nhắc khi đang ở Sandbox — kèm chỗ sửa. Cần GP247 Core có khung này; core cũ hiển thị màn như trước.
- **1.1.0** — Stripe thu và hoàn tiền cho **Yêu cầu thanh toán** của `gp247/shop` (link thanh toán `/pay/…`), dùng khoá của cửa hàng sở hữu yêu cầu. Shop chưa có tính năng này thì plugin chạy như 1.0.0. Yêu cầu GP247 Core 3.1+.
- **1.0.0** — Phát hành đầu tiên: Stripe Checkout, webhook có chữ ký, đồng bộ hoàn tiền, cấu hình theo cửa hàng, mã
  hoá khoá bí mật.
