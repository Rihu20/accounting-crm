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

## Workflow

1. Review company details, tax rate, numbering, banks and gateway instructions under Settings. Seeded company details come from the specification and must be verified. Bank account numbers are deliberately empty.
2. Add masters directly or upload the completed supplied Excel in Import masters. Review the preview and confirm. Invalid rows block the import. Re-import updates existing keys. Ref No is a four-character text string.
3. Download the fee Word template. Word uploads create drafts only after validation and confirmation. Admins can also make a proforma manually. Review/edit its items, currency and bank, then finalise.
4. Approvers record partial or full payments. Received proformas expose Order Tax Invoice to Admin. PDF, matter folders and notifications are generated.
5. Record vendor invoices or owner expenses. Owners see their own expenses. Admin records vendor payments and owner reimbursements.

## Local defaults and production setup

The included local app uses SQLite because this computer has no MySQL server. Migrations also target MySQL 8. For shared deployment, set DB_CONNECTION=mysql and DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in `.env`; run `php artisan migrate --seed`. Do not copy a live SQLite database into MySQL. Use HTTPS, APP_ENV=production, APP_DEBUG=false, a unique APP_KEY, and your own administrator credentials. Point the web server only at `public/`. The local web server is for this computer only.

Private local file storage works immediately. Set CRM_DISK=s3 for S3-compatible storage and configure AWS variables. Google Drive support uses CRM_DISK=google and GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET, GOOGLE_DRIVE_REFRESH_TOKEN, GOOGLE_DRIVE_FOLDER. These external services need real credentials and have not been connected here. Never commit `.env` or private documents.

Set mail transport variables and CRM_MAIL_ENABLED=true to send email notifications. In-app notifications work without mail. Run `php artisan queue:work` for queued extraction and `php artisan schedule:work` for the local scheduler. On the server, run `php artisan schedule:run` every minute. Daily checks run at 09:00 Asia/Kolkata and are idempotent. Unpaid proformas are reminded at 15 days, then every 7 days; settings are configurable.

Vendor PDF extraction requires ANTHROPIC_API_KEY and ANTHROPIC_MODEL in `.env`. Manual entry always works. Extraction results require Admin review and confirmation. No AI API call is made without configuration.

PDF rendering uses the specification's supported Dompdf fallback with explicit page chunking, repeated headers and carry-forward totals. This avoids installing a separate Chromium process on this Windows computer. Very long descriptions are limited on entry; verify printed invoice layouts with real company content before production. No email is automatically sent on finalising a proforma: finalisation stores its PDF and notifies the paralegal; download it for delivery to the client.

## Backup

Before real data, configure a daily encrypted off-device backup of the database, private file disk and `.env`. For local SQLite, stop the app before copying database/database.sqlite and storage/app/private. For MySQL, use a transaction-consistent mysqldump and backup the configured cloud disk separately. Keep daily and weekly versions and test restore to a separate environment. No off-device backup destination is configured automatically.

## Tests

`..\.tools\php\php.exe artisan test` runs the business-rule suite against a disposable in-memory database. MySQL row-lock concurrency and external email/cloud services need deployment-environment checks.
