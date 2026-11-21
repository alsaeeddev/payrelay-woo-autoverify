# PayRelay Privacy Policy

**Effective Date:** November 16, 2026  
**Application:** PayRelay (`com.alsaeeddev.payrelay`)  
**Purpose:** Companion SMS payment-verification relay for the merchant's self-hosted WooCommerce site running the *Manual Payment Auto-Verify* (`mpav`) plugin.

---

## 1. Merchant-Controlled, Self-Hosted Architecture
PayRelay operates strictly between **your Android device** and **your own WordPress/WooCommerce HTTPS website**. The developer (`alsaeeddev`) does not operate any intermediate relay server, cloud database, analytics service, crash reporter, or advertising network.

## 2. Permissions & Why They Are Requested
PayRelay follows strict least-privilege principles and requests only:
- **`RECEIVE_SMS` (Required):** Inspects incoming SMS broadcasts in real time so allowlisted payment notifications can be forwarded to your store. PayRelay **never** requests `READ_SMS` (cannot browse your SMS inbox history) and **never** requests `READ_CONTACTS`.
- **`CAMERA` (Optional, Pairing Only):** Used solely to scan the pairing QR code displayed inside your WordPress admin dashboard. You may instead enter your HTTPS site URL and pairing token manually without granting camera permission.
- **`POST_NOTIFICATIONS` (Recommended):** Displays local alerts when background sync fails authentication, encounters clock skew, or is actively processing a queued delivery.

## 3. Strict Sender Allowlist & Immediate Drop Policy
Incoming SMS messages are checked immediately against your local **Sender Allowlist** (preloaded with common Pakistani mobile wallet and bank sender IDs such as JazzCash and Easypaisa, and fully editable in Settings).
- **Non-allowlisted SMS** (personal chats, OTPs, promotional messages) are **dropped immediately in memory**—they are never written to storage, never logged, and never transmitted over the network.
- **Allowlisted payment SMS** are queued locally in an outbox table only until delivered to your WordPress `/wp-json/mpav/v1/sms` endpoint (or permanently rejected by the server), after which the SMS body is immediately cleared from the database.

## 4. Data Transmitted to Your WordPress Server
When an allowlisted payment SMS arrives, PayRelay sends only the following fields over **HTTPS (TLS)** to the WordPress URL you configured:
- `idempotency_key` (SHA-256 hash of sender, message body, and timestamp)
- `sender` (SMS sender ID)
- `body` (payment SMS content)
- `received_at_ms` (epoch timestamp in milliseconds)
- `sim_slot` (SIM slot index when reported by the device)

During periodic 15-minute heartbeats (`/wp-json/mpav/v1/heartbeat`), PayRelay sends only `app_version` and `battery_optimized` (`true`/`false`).

## 5. Local Security, Redaction & Retention
- **Credential Storage:** Your WordPress pairing secret is encrypted at rest using **Android Keystore (AES-256-GCM)** and is excluded from Android cloud backups and device-to-device data extraction (`allowBackup="false"`).
- **Request Authenticity:** Every API call is signed with **HMAC-SHA256**, a fresh cryptographic nonce, and a Unix timestamp.
- **Redacted Logs & Screen Protection:** Local activity logs automatically redact phone numbers, currency amounts, and long numeric sequences; release builds strip all `android.util.Log` diagnostics. Sensitive pairing and lock screens apply `FLAG_SECURE` to block screenshots and screen recording.
- **Automatic Pruning:** Processed outbox records and local audit logs are automatically pruned after your configured retention window (default 14 days, configurable from 1 to 90 days).

## 6. Third-Party SDKs & Analytics
PayRelay contains **no** third-party analytics, telemetry, crash-reporting, or advertising SDKs. QR decoding is performed locally on-device.

## 7. User Control & Data Deletion ("Unpair & Wipe")
You may pause or resume SMS forwarding at any time from the Home screen, edit or clear the sender allowlist in Settings, or tap **Unpair & Wipe All Data** in Settings to immediately cancel all background jobs, delete the Android Keystore encryption key, and erase all local databases and preferences.
