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
