# Waiver Management System (Full) — PHP + MySQL

Includes:
- Admin templates + versioning
- Walk-ins (no reservation), group_token for parties
- Link selected signed responses or whole group to a reservation
- Stats (by reservation, by group, last 14 days, etc.)
- DOCX import: staff author in Word using placeholders → rich HTML form + PDF
- Docker Compose stack (Nginx + PHP-FPM 8.2 + MySQL 8.0)

## Quick Start (Docker)

1) Copy config:
```bash
cp config/config.php.docker config/config.php
# edit api_hmac_secret
```

2) Start:
```bash
docker compose up -d --build
docker compose exec php composer install
```

3) Seed admin:
```bash
docker compose exec php php dev/seed_admin.php admin@example.com yourStrongPassword
```

4) Open:
- Admin: http://localhost:8080/admin.php
- API:   http://localhost:8080/api.php
- Guest links are generated via API.

## DOCX Authoring (placeholders)
- `{{text:full_name}}` (required: `{{text:full_name!}}`)
- `{{textarea:medical}}`
- `{{radio:consent_photo:Yes|No}}` (required: `{{radio:consent_cctv!:Yes|No}}`)
- `{{signature}}`

After import, you can review **Fields JSON**, **Content HTML**, and optional **Print CSS** before publishing.

## API HMAC example
```bash
BODY='{"action":"create_waiver","template_id":1,"reservation_id":"RES-1"}'
SECRET='CHANGE_ME_TO_A_LONG_RANDOM_SECRET'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | awk '{print $1}')
curl -X POST http://localhost:8080/api.php   -H 'Content-Type: application/json' -H "X-HMAC: $SIG" --data "$BODY"
```

## Boot-time secret sentinel
Every public entrypoint (`api.php`/`admin.php`/`w.php`) refuses to boot if
`config/config.php` still carries a placeholder `CHANGE_ME_TO_A_LONG_RANDOM_SECRET`
value for `security.api_hmac_secret`, any `security.inbound_hmac_keys` entry,
or `callback.outbound_secret` (when `callback.base_url` is configured) — see
`Utils::assertNoPlaceholderSecrets()`. Make sure you actually replace those
values after `cp config/config.php.docker config/config.php`, not just edit
`api_hmac_secret` as the quick-start above shows.

## Evidence lock wait times (`evidence_lock.*`)
`submitGuestForm`, `resendEvidence` and `eraseWaiver` all serialize against
each other with one MySQL named lock per waiver instance (`GET_LOCK`, so an
evidence upload/record and a GDPR erasure of the SAME instance can never
interleave). Each of the three actions waits a different, independently
tunable number of whole seconds for that lock before giving up — see
`WaiverController::evidenceLockWaitSeconds()`. All three keys are optional;
config sample files with all of them, and what each guards, are in
`config/config.php` and `config/config.php.docker` (commented out). Set only
the ones you need to override under a top-level `'evidence_lock' => [...]`
array in `config/config.php`:

| Key | Default | Used by |
| --- | --- | --- |
| `submit_wait_seconds` | `2` | A guest's first submit. On timeout it still completes (the signature is never lost) but WITHOUT uploading evidence — the PDF/signature are retained locally for `resend_evidence`/reconcile to push later. |
| `resend_wait_seconds` | `0` | `resend_evidence`. Never waits; a busy lock just means "nothing new to push right now" (`pushed:false`, not an error). |
| `erase_wait_seconds` | `5` | `erase_waiver`, per matched instance (ascending id order, ALL locks taken before its transaction opens). On timeout it answers `{error:'evidence_busy'}` (503) and deletes nothing — safe for BookingV2's outbox to retry. |

## Staging repair / re-provisioning
If the staging fork database is re-provisioned empty (0 templates, 0 admins),
see `dev/staging-repair-runbook.md` — it covers the two operator steps (the
`SEED_ADMIN_*` variables, and the id-preserving template copy armed by
`SEED_WAIVER_TEMPLATE_FILE`) and why neither can ever touch production.

## Tests
See `tests/README.md` for the phpunit micro-harness (setup, one-time
`waiver_test` DB creation, and what it covers). Run with:
```bash
docker compose exec php vendor/bin/phpunit
```
