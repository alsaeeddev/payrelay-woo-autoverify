=== Manual Payment Auto-Verify for WooCommerce ===
Contributors: Al Saeed Dev
Tags: woocommerce, pakistan, manual payment, payment gateway, sms verification
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manual payment gateway for WooCommerce in Pakistan. Verifies mobile wallet and bank transfer payments automatically from SMS forwarded by a companion Android app.

== Description ==

**Manual Payment Auto-Verify for WooCommerce (MPAV)** brings automated, auditable and reliable verification to manual mobile wallet and bank transfer payments in Pakistan.

Customers pay your wallet or bank account and enter the Transaction ID (TID) at checkout. Normally you then check your app by hand while the order sits *On hold*. MPAV removes that step: a companion Android app on your own phone forwards the incoming payment SMS to your store over a signed connection, the plugin matches it with the customer's claim, and the order moves to **Processing** within seconds.

= How it works =

1. The customer pays and enters the TID at checkout. The plugin creates a payment claim.
2. Your merchant phone receives the payment confirmation SMS. The companion app forwards it to your site.
3. The plugin extracts the amount and TID, then checks TID, amount, provider and time window.
4. A full match confirms the order automatically. Anything else is flagged with a clear reason for your review.

The SMS and the customer's TID can arrive in **either order**; both cases are handled, and a background check re-verifies pending claims so none is expired by mistake.

= Features =

* **Manual payment gateway** with per-provider account number, QR code and instructions.
* **Classic and Block checkout** support, with a clear step-by-step payment layout: amount to send, account to pay (with copy button and QR), then TID and sender number.
* **Automatic verification** of TID, amount and time window, in either arrival order.
* **Works with different SMS formats:** a universal extractor reads the amount and TID from wallet and bank messages, ignores balance and fee figures, and accepts only incoming (credit) PKR payments.
* **Verification Queue** with status tabs, provider filter, bulk actions and a single search box (order number or TID).
* **Device Manager:** pair phones by QR code, revoke a device, clear all devices, and see the list update the moment a phone connects. Pairing the same phone again reuses its entry.
* **Fraud and double-claim protection:** a Transaction ID can be claimed only once; every state change is locked and audit-logged.
* **Security by design:** HMAC-SHA256 signed requests, anti-replay nonces, encrypted device secrets (libsodium or AES-256-GCM), rate-limited REST endpoints.
* **Automatic expiry and housekeeping** of stale claims and old records.
* **Privacy friendly:** raw SMS text is never stored, phone numbers are masked, and the plugin integrates with the WordPress personal data exporter and eraser.

= Companion Android app =

Auto-verification needs the free companion Android app (PayRelay) installed on the phone that receives your payment SMS. The app is distributed as an APK from the project repository. It is not listed on Google Play because Google restricts SMS permissions for Play apps. The plugin works without the app as a regular manual gateway: orders wait for your manual approval.

= Disclaimer =

This is an independent project. It is not affiliated with or endorsed by any mobile wallet provider or bank. Provider names and short codes are used only to recognise incoming messages. You are responsible for compliance with local regulations and for your customers' privacy.

== Installation ==

1. Upload the `manual-payment-autoverify` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin.
3. Go to **WooCommerce > Settings > Payments**, click **Manage** next to **Manual Payment (Mobile Wallet / Bank Transfer)**, and enable it.
4. Enter your recipient account numbers and titles, and optionally a QR code and instructions.
5. (Optional, for auto-verification) Go to **WooCommerce > Manual Payments > Devices**, click **Pair New Device** and scan the QR code with the companion Android app.
6. Your site must use **HTTPS**. The app refuses plain HTTP.

== Frequently Asked Questions ==

= Do I need a paid SMS gateway? =
No. The phone that already receives your payment SMS does the work. There is no SMS API subscription or per-message fee.

= What happens if the customer sends the wrong amount? =
The claim is flagged with the reason (for example "Amount mismatch: expected Rs. 2,000, received Rs. 1,500") and appears in **WooCommerce > Manual Payments > Queue**, where you can approve or reject it.

= Why was my test payment not verified? =
Only incoming (credit) messages are accepted. A message like "you sent Rs 500 to ..." belongs to the sender's account and is ignored on purpose. Test by paying your merchant account from a different account, and use the exact order total.

= What if the SMS arrives before the customer enters the TID? =
It is stored (as parsed fields only) and matched the moment the claim is created. Pending claims are also re-checked in the background before they expire.

= Which senders are forwarded? =
The app has an editable allowlist of common Pakistani wallet and bank senders. Messages from any other sender are dropped on the phone.

= Does it work on iPhone? =
No. SMS forwarding needs Android.

= Are raw SMS messages stored on my server? =
No. Messages are processed in memory; only parsed fields (amount, TID, provider, time) and a one-way SHA-256 hash are kept.

= Does checkout still collect payment screenshots? =
No. Verification is based on the forwarded SMS. Screenshots uploaded with earlier versions stay in a protected folder and can be viewed only by users with the `manage_woocommerce` capability.

== Privacy ==

* The plugin makes no calls to third-party services.
* The companion app sends data only to the site you pair it with.
* Stored data: claims (order, provider, normalised TID, amount, status), parsed SMS fields and hashes, device records (name, encrypted secret, last seen), and an audit log. Customer phone numbers are masked.
* Personal data export and erasure are supported through the WordPress privacy tools.

== Screenshots ==

1. Customer checkout: amount to send, payment method, account details and TID fields.
2. Verification Queue with flagged items, search and one-click actions.
3. Device Manager with paired phones and heartbeat status.
4. Settings for the matching window, claim expiry and data retention.

== Changelog ==

= 1.1.0 =
* New: universal SMS extractor. Reads the amount and TID from any wallet or bank SMS, ignores balance and fee figures, accepts only incoming PKR payments.
* New: verification works whether the SMS or the customer's TID arrives first, with a background safety check before claims expire.
* New: provider mismatch handling. Same TID but a different provider is flagged for review and never auto-verified.
* New: redesigned checkout for classic and block checkout (amount banner, numbered steps, provider cards, clearer account and copy panel, inline hints and errors, responsive and RTL-safe).
* New: styled payment method row with an icon and a clean details card.
* New: search box on the Verification Queue (order number or TID) with result banner, Clear button, Esc to clear and highlighted matches.
* New: Clear All Devices on the Devices page. Removes every paired device, deletes their secrets and writes an audit entry.
* New: the Devices page refreshes itself as soon as a phone finishes pairing.
* New: pairing the same phone again reuses its device entry instead of adding a duplicate (the app sends an optional random install ID).
* Changed: payment screenshots are no longer collected at checkout. Existing claims and stored screenshots are unaffected.
* Fix: checkout stylesheet did not load because the asset URL was built from an empty path. Assets are now versioned by file time, so updates are never served from a stale cache.
* Fix: the account details panel could stay hidden with a single account or after the checkout refreshed. The selected provider and typed values are now kept across refreshes.
* Fix: pressing Enter in the Verification Queue search field now runs the search.

= 1.0.0 =
* Initial release: manual gateway, HMAC-SHA256 signed REST API, anti-replay nonce store, rate limiting, claim matching with integer (paisa) math, background expiry and cleanup jobs, WordPress privacy exporter and eraser, Cart and Checkout Blocks support.

== Upgrade Notice ==

= 1.1.0 =
Adds a small database update (device install ID) that runs automatically. Update the Android app too to get duplicate-free re-pairing; older app versions keep working.