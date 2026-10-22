# StripePayment Plugin

> 🌐 **Language:** [🇻🇳 Tiếng Việt](./readme_vi.md) · 🇬🇧 English (current) · [🇯🇵 日本語](./readme_ja.md)

Accept **Stripe** payments in your GP247/Shop store: international cards, Apple Pay, Google Pay, Link and the local
payment methods you enable on your Stripe account. Customers pay on Stripe's secure page and come back to your store —
card details never pass through your website.

---

## At a glance

| | |
| --- | --- |
| **Plugin** | StripePayment |
| **Version** | 1.1.2 |
| **Developer** | GP247 |
| **License** | Free |
| **Requires** | GP247 Core **3.1+** (per-store config, at-rest secret encryption, payment requests) · package `gp247/shop` |
| **Does not need** | Extra Composer packages, cron, queue workers |

## What it does

- 💳 **Stripe Checkout** — a Stripe-hosted payment page with 3-D Secure and wallets built in.
- 🧾 **Money recorded on the order** — the amount Stripe actually collected is written to the order's payment ledger,
  exactly once, even if the customer closes the browser before reaching the thank-you page (via webhook).
- ↩️ **Refund sync** — partial or full refunds made in the Stripe Dashboard are recorded on the order.
- 🧪 **Test & Live** — one switch between test keys and live keys.
- 🏬 **Per store** — each store can use its own Stripe account, or share the site/marketplace account.
- 🔒 **Secure** — webhook signatures are verified; the secret key and webhook secret are **encrypted** in the database;
  only the site/marketplace owner can change them.
- 💱 **Correct currency units** — VND, JPY and other zero-decimal currencies are sent the way Stripe expects.

| Situation | What the plugin does |
| --- | --- |
| Customer pays | Records the payment on the order and moves it to the status you chose (default *Processing*) |
| Customer clicks back on the Stripe page | Cancels the order and returns the items to stock |
| Customer abandons the payment | The order stays pending — you decide whether to cancel it or contact the customer |
| Delayed payment method (bank transfer / debit) | The order is placed; money is recorded when Stripe confirms it; a failure is noted in the order history |
| Refund in Stripe | Records the refund; only a full refund moves the order to *Refunded* |
| Stripe collected a different amount than the order total | Records what was actually collected, notes the difference, and does **not** mark the order paid |

---

## Payment links (payment requests)

From **1.1.0**, with a `gp247/shop` that has the **Payment requests** screen, Stripe also collects money **outside the cart** — the balance of an order, a receivable, a deposit, a free-form invoice:

1. The admin creates a request to collect in **Payment requests**, clicks **Create payment link** and sends the link to the customer.
2. The customer opens the link, picks **Stripe**, pays on Stripe's page and comes back to the link's page with a confirmation.
3. The money is recorded on the request **exactly once** (the webhook and the return page may both arrive).
4. Refunds: on a collected line, click **Refund via gateway** (needs the money-out permission). Refunds made directly in the Stripe Dashboard are recorded on the request too.

Nothing more to configure: same keys, same webhook (the events listed below). Stripe only shows on the link's page when the **store that owns the request** has a secret key — the money always goes to that store's Stripe account.

## Installation

1. Copy the `StripePayment` folder to `app/GP247/Plugins/` on your website (or upload the ZIP in
   **Admin → Extensions / Plugins → Import**).
2. Go to **Admin → Extensions / Plugins**, find **Stripe** and click **Install**.

The plugin is enabled after installation, but it can only take payments once you enter your Stripe keys (below).

---

## Configuration

Open **Admin → Plugins → Stripe** (or click the plugin in the list). The settings screen has **4 blocks**, so test keys
and live keys never sit side by side:

| Block | Field | What to enter |
| --- | --- | --- |
| **Mode** | Test mode | On = the **Sandbox** block is used (no real money) · Off = the **Live** block. The block in use shows an **In use** badge. This block's description shows the **webhook URL** and the events to select |
| **Sandbox (test)** | Secret key | An `rk_test_…` key (recommended) or `sk_test_…` — see [Create your Stripe keys](#create-your-stripe-keys) |
| | Webhook signing secret | The `whsec_…` of a webhook endpoint created **in the sandbox** |
| **Live** | Secret key | An `rk_live_…` key (recommended) or `sk_live_…` |
| | Webhook signing secret | The `whsec_…` of a webhook endpoint created **in live mode** |
| **Order status** | After payment · After a full refund | Default *Processing* · *Refunded* |

Secrets are **encrypted** when saved and are **never shown again** on screen — each field only says *Saved* or
*Not set*. Leaving a secret field empty when you click **Save** keeps the current value; to change it, paste a new one.

---

## Create your Stripe keys

You need **two things per environment**: an **API key** (to create checkout sessions) and a **webhook signing secret**
(to verify the notifications Stripe sends back). Sandbox and Live are **two separate sets** — a key from one does not
work in the other.

| Stripe environment | Block on the settings screen | API key starts with | Webhook secret |
| --- | --- | --- | --- |
| **Sandbox** (testing, no real money) | Sandbox (test) | `rk_test_` or `sk_test_` | `whsec_…` of an endpoint in the sandbox |
| **Live** (real money) | Live | `rk_live_` or `sk_live_` | `whsec_…` of an endpoint in live mode |

> Finish **Sandbox** first, make a successful test payment, then do **Live**. To use Live, your Stripe account must be
> activated (business details and the bank account that receives payouts).

### Step 1 — Pick the right environment in Stripe

1. Sign in to [dashboard.stripe.com](https://dashboard.stripe.com).
2. In the account picker (top left), choose **Sandboxes** and open a sandbox (create one if you have none) — or stay
   on your main account to set up **Live**.
3. Do every step below **inside the environment you picked**. When Sandbox is done, come back here for Live.

### Step 2 — Create the API key

**Recommended: a restricted key** (it gets only the permission the plugin needs — if it ever leaks, it cannot be used
for anything else).

1. Open the **API keys** page ([dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys)).
2. Click **Create restricted key**.
3. **Key name**: something you will recognise, for example `GP247 StripePayment – your-domain`.
4. Leave every permission at **None**, **except** **Checkout Sessions** → **Write**. The plugin only creates and reads
   checkout sessions and needs nothing else.
5. Click **Create key** and enter the verification code Stripe sends by email or text message.
6. **Copy the key right away** (click its value). In **Live**, a key you create is **shown only once** — if you close
   the dialog before copying it, create another key.
7. Paste it into the **Secret key** field of the **matching block**: an `rk_test_…` key → **Sandbox** block; an
   `rk_live_…` key → **Live** block.

**Quick alternative: the standard secret key** (`sk_…`, full access to the account — less safe): on the **API keys**
page, under **Standard keys**, click **Reveal** on the *Secret key* row and copy it. In a sandbox you can always reveal it
again; in Live you can only reveal the key Stripe created for you.

### Step 3 — Create the webhook and get its signing secret

Without a webhook, payments are only recorded when the customer returns to the thank-you page, and refunds made in
Stripe do not reach the order.

1. On the GP247 Stripe settings screen, copy the **webhook URL** from the description of the **Mode** block, like
   `https://your-domain/plugin/stripe-payment/webhook`.
2. In Stripe, open **Workbench → Webhooks** ([dashboard.stripe.com/webhooks](https://dashboard.stripe.com/webhooks))
   → **Create an event destination**.
3. Choose **Your account** as the event source; leave the API version at its default.
4. Select exactly these 6 events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`, `refund.created`, `refund.updated`, `refund.failed`.
5. Choose **Webhook endpoint** as the destination type, paste the URL from step 1 into **Endpoint URL**, then create it.
6. On the new endpoint's page, click **Reveal** under **Signing secret** and copy the `whsec_…` value.
7. Paste it into the **Webhook signing secret** field of the **same block** as the API key from Step 2 (Sandbox or
   Live), then click **Save**.

The webhook needs a **public HTTPS** address. To test on your own computer (no HTTPS yet), use the
[Stripe CLI](https://docs.stripe.com/stripe-cli):
`stripe listen --forward-to http://localhost/plugin/stripe-payment/webhook` — it prints a `whsec_…` secret; paste that
secret into the **Sandbox** block.

### Check before saving

- The **Sandbox** block holds only keys containing `_test_`; the **Live** block only keys containing `_live_`.
- The API key and the webhook secret in the **same block** come from the **same** Stripe environment.
- The **Test mode** switch matches the block you want to use (the **In use** badge moves to that block after **Save**).

### Keep your keys safe

- Never send keys by email, chat or screenshot. Paste them straight into the settings screen.
- If you think a key has leaked: in Stripe, **API keys** page → the key's **⋯** menu → **Rotate key**, then paste the
  new key into GP247. A webhook secret can be rolled the same way (**Roll secret**) on the endpoint's page.

### Several stores / marketplace

- **Multi-store**: pick the store at the top of the settings screen and enter that store's Stripe keys. Register one
  webhook endpoint per domain — **each endpoint has its own signing secret**, so enter it on the matching store, even
  when the stores share one Stripe account.
- **Marketplace**: enter the keys at the shared level; stores left empty use the marketplace account.
- Turn Stripe on or off per store in the **Plugin manager** screen.
- Store admins and vendors cannot open this settings screen.

---

## Test a payment

Turn on **Test mode**, place an order and choose Stripe, then pay with the test card `4242 4242 4242 4242`, any future
expiry date and any CVC. More test cards are in Stripe's *Testing* documentation.

## Good to know

- **Currency**: the order currency must be supported by your Stripe account and meet Stripe's minimum amount (for
  example 0.50 USD, 50 JPY). If Stripe refuses, the order is cancelled, the items go back to stock and the customer is
  asked to choose another payment method.
- Stripe shows **one line** "Order #…" for exactly the order total (the product list is in the description), so the
  amount always matches the order, including discounts, tax and shipping.
- **Refunds** are made in the Stripe Dashboard. If Stripe reports a failed refund, a note is added to the order so you
  can handle it by hand.
- Uninstalling removes only the plugin's settings; payments and refunds already recorded on orders are kept.

## Version history

- **1.1.2** — Japanese (日本語) translation of the plugin screens.
- **1.1.1** — The settings screen opens with a **Check before use** box: what still stops shoppers from paying with Stripe (payment step off at checkout, Stripe off for the store, missing secret key or webhook signing secret of the mode in use, a webhook URL Stripe cannot reach) and a reminder while Sandbox mode is on — each with where to fix it. Needs a GP247 Core that has this box; older cores show the screen as before.
- **1.1.0** — Stripe collects and refunds the **payment requests** of `gp247/shop` (payment links `/pay/…`), with the keys of the store that owns the request. On a shop without that feature the plugin behaves as 1.0.0. Requires GP247 Core 3.1+.
- **1.0.0** — First release: Stripe Checkout, signed webhooks, refund sync, per-store settings, encrypted secrets.
