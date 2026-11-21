# Merchant Gateway Setup & Android Companion Pairing Guide

This guide explains how to configure **Manual Payment Auto-Verify for WooCommerce (MPAV)** and pair your merchant Android phone to automatically verify incoming mobile wallet transactions.

---

## 1. Gateway Configuration in WooCommerce

1. Navigate to **WooCommerce > Settings > Payments**.
2. Locate **Manual Payment (JazzCash / Easypaisa / Bank)** and click **Manage**.
3. Enable the gateway and configure the following options:
   - **Title & Description:** Customer-facing instructions displayed during checkout.
   - **Supported Providers:** Enable JazzCash, Easypaisa, and/or Direct Bank Transfer.
   - **Account Numbers & Titles:** Specify your recipient mobile numbers or bank IBAN details.
   - **Claim Expiry (minutes):** How long an unverified claim stays open (default: 1440 = 24 hours, minimum: 30). When it runs out, the claim expires and the WooCommerce order is cancelled.
4. Save changes.
5. Open **Manual Payments → Settings** for the remaining options:
   - **SMS match window (minutes):** How long before a claim was created an SMS may still match it (default: 60).
   - **SMS retention (days):** How long parsed SMS records are kept before the cleanup job removes them (default: 90).

---

## 2. Pairing the Merchant Android Device

The automated verification engine relies on an Android phone with the **PayRelay** app that receives the merchant's payment SMS (for example from **JazzCash 8558** or **Easypaisa 3737**). The app is installed as an APK from the repository's Releases page; make sure the site uses **HTTPS**, because the app refuses plain HTTP.

### Step-by-Step Pairing via QR Code
1. In your WordPress admin dashboard, go to **Manual Payments > Devices**.
2. Click **Pair New Device**.
3. A modal will display a secure QR code containing:
   - The WordPress site REST API base URL.
   - A one-time 64-character pairing token (valid for 10 minutes).
4. Open the **PayRelay** app on your merchant device and accept the consent screen.
5. Tap **Scan QR Code** and point your camera at the screen (or enter the site URL and token manually).
6. The Android app securely calls `POST /wp-json/mpav/v1/pair` and exchanges the token for:
   - A `device_id` (an integer assigned by the store, sent as a string).
   - A 256-bit symmetric signing secret.
7. The page refreshes by itself as soon as the phone connects, and the device appears in the **Devices** list. If the phone does not connect, the QR code stays on screen until the token expires (10 minutes) or you close the window.

> **Pairing the same phone again** reuses its existing row, so you do not get duplicates. If the app's storage was cleared or the app was reinstalled, the phone counts as a new device; revoke the old entry or use **Clear All Devices**. The `ID: #n` shown for a device is a database counter that only goes up; it is not the number of devices.

---

## 3. Webhook Specification (`/wp-json/mpav/v1/sms`)

When the Android device receives an SMS matching a known provider shortcode, it sends a signed HTTP POST request to your WordPress site.

### Endpoint Details
- **Method:** `POST`
- **Path:** `/wp-json/mpav/v1/sms`
- **Content-Type:** `application/json`

### Required HTTP Headers
| Header | Description | Example |
|--------|-------------|---------|
| `X-MPAV-Device` | Device ID returned by `/pair` | `5` |
| `X-MPAV-Timestamp` | Unix epoch time in seconds (must be within ±300 s of server time) | `1763262000` |
| `X-MPAV-Nonce` | Lowercase hexadecimal single-use nonce (32-128 hex chars) | `a1b2c3d4e5f60709493a4b5c6d7e8f90` |
| `X-MPAV-Signature` | 64-character lowercase hex HMAC-SHA256 signature | `884b24a69644860e9bb5456c4bb5b71cee565e816a244fc599ee374b0c5fa0e2` |

### JSON Request Body
```json
{
  "idempotency_key": "msg-unique-uuid-1234",
  "sender": "8558",
  "body": "You have received Rs. 1500.00 from 03001234567. Your new JazzCash balance is Rs. 5000.00. TID: 0123456789.",
  "received_at_ms": 1763262000000,
  "sim_slot": 0
}
```

### Signature Computation Algorithm
The signature string joins the following elements using newline (`\n`):
```
METHOD
PATH
TIMESTAMP
NONCE
HEX(SHA256(RAW_REQUEST_BODY))
```

**PHP Signature Calculation Example:**
```php
$body_hash = hash('sha256', $raw_json_body);
$signing_string = implode("\n", [
    'POST',
    '/wp-json/mpav/v1/sms',
    $timestamp,
    $nonce,
    $body_hash
]);
$signature = hash_hmac('sha256', $signing_string, $device_secret);
```

---

## 4. Heartbeat & Health Monitoring

The paired Android companion app emits a periodic heartbeat (`POST /wp-json/mpav/v1/heartbeat`) every 15 minutes. 
- In **Manual Payments > Devices**, you can see each device's **Last Seen** time and app version. A green dot means the device contacted the store within the last 5 minutes; otherwise it is shown as idle or offline.
- If SMS stop arriving, check the device's battery optimization settings (see the in-app Reliability guide).
- You can revoke a device at any time by clicking **Revoke Device**. Revoked devices are permanently blocked from sending webhook updates.

---

## 5. Troubleshooting & FAQ

### Order did not auto-verify?
1. Check **Manual Payments > Verification Queue**:
   - If the claim is listed as **Flagged**, open it to see the reason (e.g., amount mismatch, provider mismatch or time window exceeded).
   - You can approve or reject the claim with one click.
2. Check **Manual Payments > Audit Log**:
   - Verify that the SMS webhook was received from the Android device.
   - Look for any signature verification or rate limit errors.
3. Verify that your server clock is synchronized via NTP (HMAC signatures allow a ±300-second drift).

