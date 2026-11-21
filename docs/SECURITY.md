# Security Policy & STRIDE Threat Mitigation Matrix

Manual Payment Auto-Verify is engineered with a strict **Security by Design** posture. Because this plugin handles financial transactions, incoming webhooks, and customer identity data, all inputs are treated as untrusted and audited against the STRIDE threat model.

---

## STRIDE Threat Mitigation Matrix

| # | STRIDE Category | Threat Description | Mitigation Strategy & Implementation Details | Code Invariant |
|---|-----------------|-------------------|----------------------------------------------|----------------|
| **1** | **Spoofing** | Attacker spoofs SMS webhook to verify unpaid orders. | Every incoming webhook request is signed with HMAC-SHA256 using a symmetric pre-shared secret unique to the paired device. Unauthenticated or invalidly signed requests are rejected with 401 before any database operations occur. | `HmacAuthenticator::verify()`, constant-time `hash_equals()` |
| **2** | **Repudiation / Replay** | Attacker captures a valid webhook and replays it later. | Each request requires a unique `X-MPAV-Nonce` (lowercase hex, 32-128 chars) and `X-MPAV-Timestamp` (±300 s window). Nonces are atomically consumed in `wp_mpav_used_nonces` with PRIMARY KEY constraint. Replays trigger MySQL 1062 and are dropped immediately. | `NonceStore::consume()`, `CleanupJob` |
| **3** | **Tampering** | Man-in-the-middle alters payment amount or TID in transit. | The request body is digested into a SHA-256 hash and bound directly into the canonical signing string: `METHOD + "\n" + PATH + "\n" + timestamp + "\n" + nonce + "\n" + hex(SHA256(body))`. Any modification invalidates the signature. | `HmacAuthenticator::compute_signature()` |
| **4** | **Information Disclosure** | Error responses disclose internal system state or validation failures. | Failed REST authentication requests return a uniform generic response (`401 Unauthorized`) without revealing which check failed (signature, timestamp, nonce, or secret). | `RestRegistrar::permission_callback` |
| **5** | **Information Disclosure** | PII leakage via database dumps or unauthorized inspection. | Raw SMS message bodies are **never persisted**. Inbound SMS is processed in memory; only parsed fields (provider, TID, amount) and a one-way SHA-256 hash of the body are stored. Customer phone numbers are masked in logs and displays (`0300*****67`). Device secrets are encrypted at rest using `libsodium` or `OpenSSL AES-256-GCM`. | `PhoneNumberMasker::mask()`, `EncryptionService::encrypt()`, `SmsRepository` |
| **6** | **Denial of Service** | Flooding webhook endpoints with large payloads or ReDoS attacks. | REST endpoints enforce body size limits (64 KB max for `/sms`, 1 KB for `/heartbeat`). Rate limiting uses fixed one-minute windows backed by transients (60 req/min per device, 120 req/min per IP, 5 req/min per IP for `/pair`). All regex parsers are linear-time (`O(N)`), anchored, and tested against nested quantifier ReDoS attacks. | `RateLimiter::check_ip()`, `EasypaisaParserTest::test_redos_safety()` |
| **7** | **Tampering** | Duplicate submission or double spending with the same TID. | Database schema enforces `UNIQUE KEY (provider, tid_normalized)`. A TID can only ever be claimed once across the store. | `wp_mpav_claims` schema |
| **8** | **Repudiation** | Race condition between simultaneous claim transitions. | Claim state transitions use InnoDB row locking and optimistic concurrency checks: `UPDATE wp_mpav_claims SET status = %s WHERE id = %d AND status = %s`. If a concurrent transition occurs, the query returns 0 rows and fails safely. | `ClaimStateMachine::transition()` |
| **9** | **Elevation of Privilege** | CSRF attacks on manual admin review approvals/rejections. | Admin actions in `VerificationQueueTable` and `OrderMetabox` require valid WordPress nonces (`check_ajax_referer`) and explicit capability verification (`manage_woocommerce`). | `AjaxHandlers::check_permission()` |
| **10** | **Repudiation** | Audit trail tampering or missing accountability. | All state transitions, admin reviews, and automated matches are recorded in an append-only `wp_mpav_audit_log` with timestamps, actor IDs, and sanitized contextual metadata. | `ClaimStateMachine::record_audit()` |
| **11** | **Denial of Service** | Unbounded database growth from abandoned pending claims. | `ExpiryJob` executes every 5 minutes via Action Scheduler, moving stale pending claims to `expired` and cancelling the associated WooCommerce orders. | `ExpiryJob::run()` |
| **12** | **Privacy Violation** | Inability to comply with GDPR data access and right-to-erasure requests. | Implemented Core WordPress Privacy API hooks (`wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers`). Active claims are protected until order completion; terminal claims and logs are completely anonymized or unlinked. | `PrivacyExporter`, `PrivacyEraser` |

> **Note:** payment screenshots are no longer collected. The proof of payment is the matched SMS. Any file stored by an older version stays behind `ProtectedFileServer` (nonce + `manage_woocommerce` check).

---

## Security Verification Checklist for Production Deployments

1. **HTTPS Enforcement:** Always ensure SSL/TLS is active across the WordPress site. Webhook authentication headers and device secrets must be transmitted over encrypted transport.
2. **WordPress Salts:** Verify that `wp-config.php` has distinct, high-entropy salts configured. MPAV derives device encryption keys from these salts using `HKDF-SHA256`.
3. **Action Scheduler:** Ensure WordPress cron (`wp-cron.php` or system cron) is actively firing so that `mpav_expire_claims` and `mpav_cleanup` background jobs maintain database hygiene.

