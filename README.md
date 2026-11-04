# AnalystIP Accounting CRM

Laravel 13, Filament 5, PHP 8.4. One white/red panel at /admin (Fira Sans) for four fixed roles. The client, matter, paralegal and vendor masters start empty. Example Excel rows are never imported.


## Roles (fixed – defined in app/Services/PlatformAccess.php)

| Role | Can do | Cannot do |
|---|---|---|
| **Accountant** | Everything: invoices, payments, approvals, expenses, masters, users, settings | – |
| **Accountant Assistant** | Create/finalise proforma, ask for tax-invoice approval, generate approved tax invoices, credit & debit notes, fill expenses, masters, Word upload | See or change any payment / payment status, approve anything, users, settings |
| **Director** | See every invoice & expense tab, record/revert payments, settle short payments, mark bills paid/unpaid, approve tax invoices and paralegal requests, dashboard of receivables/payables | Create or edit invoices, notes or expenses |
| **Paralegal** | View proforma, tax invoices, law-firm and patent-office expenses; raise "client has initiated payment" and "please pay this firm" requests | Change anything |

Tax invoice flow: Assistant finalises proforma → Director/Accountant records payment (TDS and short-payment write-off supported) → Director/Accountant approves → Assistant (or Accountant) generates the tax invoice. The Accountant may generate directly once paid.

Old /portal, /director and /accountant links redirect to /admin. START_CRM.cmd backs up the database to database/backups and runs migrations each time it starts.

## Start on this computer

Run `START_CRM.cmd` from the parent CRM folder. It opens a first-run setup page to create your administrator login and starts the local app at http://127.0.0.1:8000/admin. The paralegal portal is http://127.0.0.1:8000/portal. Keep the window open while using the app.

Create users in Settings → Users and pick one of the four roles. Set the LUT ARN under Settings (key lut_arn) so export tax invoices print the LUT declaration.

Four demo logins (`accountant@demo.test`, `assistant@demo.test`, `director@demo.test`, `paralegal@demo.test`) are created by a migration so each role can be tried out. Their password is in the migration, so it is public. Run `php artisan crm:disable-demo-logins` before the CRM holds real work, and `php artisan crm:disable-demo-logins --list` to check: it exits non-zero while any of them is still active.

## Workflow

1. Review company details, tax rate, numbering, banks and gateway instructions under Settings. Seeded company details come from the specification and must be verified. Bank account numbers are deliberately empty.
2. Add masters directly or upload the completed supplied Excel in Import masters. Review the preview and confirm. Invalid rows block the import. Re-import updates existing keys. Ref No is a four-character text string. Masters are never deleted in the app. A client, vendor, matter or paralegal archived directly in the database keeps its name or reference reserved behind the unique index: saving or importing that value is refused with the archived record's id instead of a database error. `php artisan crm:archived-masters` lists them and `--restore=Client:12` puts one back.
3. Download the fee Word template. Word uploads create drafts only after validation and confirmation. Admins can also make a proforma manually. Review/edit its items, currency and bank, then finalise.
4. Approvers record partial or full payments. Received proformas expose Order Tax Invoice to Admin. PDF, matter folders and notifications are generated.
5. Record vendor invoices or owner expenses. Owners see their own expenses. Admin records vendor payments and owner reimbursements.

## Reports

Accountant and Director see Reports. GSTR-1 working downloads as a month workbook. Receivables ageing buckets every unpaid proforma, payables ageing buckets every unpaid vendor bill by its due date (the bill date when none is set) and shows what is not yet due. The TDS register covers a date range – the current financial year by default – with TDS deducted by clients on receipts to tally against Form 26AS, TDS deducted by us on vendor bills per section, and a summary sheet. A client statement of account lists every proforma, receipt, TDS credit, write-off and credit / debit note for one client with a running balance, one sheet per currency. Amounts are never added across currencies.

## Local defaults and production setup

The included local app uses SQLite because this computer has no MySQL server. Migrations also target MySQL 8. For shared deployment, set DB_CONNECTION=mysql and DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in `.env`; run `php artisan migrate --seed`. To carry the local data across instead of starting empty, migrate the target first and then use `crm:db-copy` (see Hosting). Use HTTPS, APP_ENV=production, APP_DEBUG=false, a unique APP_KEY, and your own administrator credentials. Point the web server only at `public/`. The local web server is for this computer only.

Private local file storage works immediately. Set CRM_DISK=s3 for S3-compatible storage and configure AWS variables. Google Drive support uses CRM_DISK=google and GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET, GOOGLE_DRIVE_REFRESH_TOKEN, GOOGLE_DRIVE_FOLDER. These external services need real credentials and have not been connected here. Never commit `.env` or private documents.

Set mail transport variables and CRM_MAIL_ENABLED=true to send email notifications. In-app notifications work without mail. Run `php artisan queue:work` for queued extraction and `php artisan schedule:work` for the local scheduler. On the server, run `php artisan schedule:run` every minute. Daily checks run at 09:00 Asia/Kolkata and are idempotent. Unpaid proformas are reminded at 15 days, then every 7 days; settings are configurable.

Vendor PDF extraction requires ANTHROPIC_API_KEY and ANTHROPIC_MODEL in `.env`. Manual entry always works. Extraction results require Admin review and confirmation. No AI API call is made without configuration.

PDF rendering uses the specification's supported Dompdf fallback with explicit page chunking, repeated headers and carry-forward totals. This avoids installing a separate Chromium process on this Windows computer. Very long descriptions are limited on entry; verify printed invoice layouts with real company content before production. No email is automatically sent on finalising a proforma: finalisation stores its PDF and notifies the paralegal; download it for delivery to the client.

## Hosting

`render.yaml` and `Dockerfile` deploy the app to Render as a Docker web service with a free Postgres database. The image installs Composer and npm, builds the Vite assets, publishes Filament's assets and serves `public/` with FrankenPHP. `docker/entrypoint.sh` runs on every container start and is idempotent: migrate, switch off the demo logins, then cache config, routes and views. Render terminates TLS at its own proxy, so TRUSTED_PROXIES=* is required – without it `isSecure()` stays false and PlatformSecurity redirects to https forever.

Push to GitHub, then in Render pick Blueprint and point it at the repository. Set APP_KEY from `php artisan key:generate --show`, and APP_URL once Render has assigned the service its URL. DB_URL is injected from the database the blueprint creates.

The `/setup` page answers on localhost alone, so a hosted database starts with no way in. `crm:first-run` fills that gap: on a database with no users it seeds the settings, bank accounts, payment gateways and patent offices, creates an administrator holding both the Accountant and Director roles, and prints a generated password once, to the deploy log. It skips itself as soon as a user exists, so the entrypoint can call it on every start. Change that password in Settings → Users after signing in.

To carry the local data up, switch off the demo logins first so their published password does not travel with it, then copy into the hosted database from this computer. Put the database's external connection string in HOSTED_DB_URL – it has its own variables so that it cannot disturb the local SQLite connection – and load `pdo_pgsql`, which the bundled PHP does not enable by default:

```
php artisan crm:disable-demo-logins
..\.tools\php\php.exe -d extension_dir=..\.tools\php\ext -d extension=php_pdo_pgsql.dll artisan crm:db-copy --force
```

`crm:db-copy` copies data, never schema, so the target must already be migrated. It orders tables by their foreign keys, fits SQLite's loosely typed values to real column types (a boolean 0 or 1 is rejected by a real boolean column), replaces the rows migrations seed rather than merging onto them so the source's own ids survive, and resets the Postgres sequences afterwards. It leaves `cache`, `sessions`, `jobs` and `migrations` behind.

What the free plan does not provide, all of which matter for real data: **the database is deleted 30 days after it is created**, so move to a paid plan or export before then; the filesystem is wiped on every deploy, so set CRM_DISK=s3 with the AWS variables or uploaded invoices and documents will not survive a restart (Cloudflare R2 is S3-compatible, free to 10 GB, and needs AWS_ENDPOINT and AWS_USE_PATH_STYLE_ENDPOINT=true); there is no cron, so `crm:daily-checks` and `crm:backup` never run and payment reminders stop arriving; there is no worker, so QUEUE_CONNECTION=sync runs vendor extraction inside the request instead; and the service sleeps after 15 minutes idle and takes about a minute to wake.

## Backup

`php artisan crm:backup` writes a dated copy of the SQLite database to `database/backups` and keeps the newest 30. It uses `VACUUM INTO`, so the copy is consistent even while the app is serving. Pass `--path` to write to another drive or a synced folder, and `--keep` to change how many are kept. The scheduler runs it daily at 02:00 Asia/Kolkata, so `php artisan schedule:work` (or `schedule:run` every minute on a server) must be running. START_CRM.cmd still takes its own copy on each start. For MySQL the command refuses to run – schedule a transaction-consistent mysqldump instead.

Before real data, configure a daily encrypted off-device backup of the database, private file disk and `.env`. For local SQLite, stop the app before copying database/database.sqlite and storage/app/private. For MySQL, use a transaction-consistent mysqldump and backup the configured cloud disk separately. Keep daily and weekly versions and test restore to a separate environment. No off-device backup destination is configured automatically.

## Tests

`..\.tools\php\php.exe artisan test` runs the business-rule suite against a disposable in-memory database. MySQL row-lock concurrency and external email/cloud services need deployment-environment checks.
