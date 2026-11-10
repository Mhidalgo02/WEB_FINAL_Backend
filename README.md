# Calapan BPLO backend

This folder is a standalone LavaLust PHP/MySQL API repository. It is deployed separately from the React frontend.

## Local start

Configure your private `.env` from `.env.example`, keep the Aiven CA private, install Composer dependencies if needed with `composer install`, and run `php lava serve 8000`. Existing database/account data can be reused. New databases use `database/bplo_schema.sql`; `scripts/create_bplo_admin.php` creates an administrator privately when needed.

## Render Web Service

Push this folder's contents to the backend GitHub repository. Create a Docker Web Service with repository root blank, Dockerfile `./Dockerfile`, context `.`, health check `/healthz.php` and automatic deployment on commit. This Dockerfile uses only files in this repository; it does not need Node or frontend files.

Set `APP_ENV=production`, a random `APP_KEY` of 32+ characters, your Aiven `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_SSL_CA=/etc/secrets/ca.pem`. Add the Aiven CA as Render Secret File `ca.pem`.

Set `ALLOW_ORIGIN` to the exact separately deployed frontend HTTPS origin, for example `https://your-frontend.onrender.com` without a trailing slash. Multiple approved origins can be comma-separated. Redeploy after changing environment values. The frontend's `VITE_API_BASE_URL` must point to this backend origin, without `/api/bplo`.

For automatic email on Render Free, use `MAIL_TRANSPORT=brevo`, `BPLO_EMAIL_ENABLED=1`, `BREVO_API_KEY`, a verified `SMTP_FROM_EMAIL` and optional `SMTP_FROM_NAME`. Free Render blocks normal SMTP ports. For SMS use `BPLO_SMS_ENABLED=1`, `TEXTBEE_API_KEY` and `TEXTBEE_DEVICE_ID`; keep the registered Android phone connected. All credentials stay in this backend, never the frontend.

Check `https://YOUR-BACKEND.onrender.com/healthz.php` for `{"status":"ok"}`. The health check verifies API runtime files/extensions, not database availability. Business requests use `/api/bplo`. No schema or account creation happens automatically on startup.

Keep `.dockerignore`, `.gitignore`, `composer.lock`, `Dockerfile` and `start-render.sh`. Do not commit `.env`, CA/private keys, runtime uploads, sessions or `vendor`. Startup creates writable runtime directories. Free Render uploads are temporary; persistent files need a paid disk mounted at `/var/www/html/backend/runtime` or external object storage. Existing local uploads are not included in the image.

## Checks

Run `php tests/automatic_notifications_test.php` and `node tests/manual_payments_http_test.mjs`. These use isolated test data and fake messaging providers. They do not access the configured Aiven database. The complete container image must also be built on Render or with a local Docker engine.