# AnalystIP Accounting CRM – project guide

Laravel 13 · Filament 5 · PHP 8.4 · SQLite locally (MySQL 8 ready) · one Filament panel at `/admin`, white/red theme, Fira Sans (bundled in `public/fonts/fira-sans`). Owner: Himanshu (accountant). He talks in Hinglish – reply in Hinglish, short and practical.

## Business in one paragraph
IP/patent services firm. Paralegals send fee Word files → a **proforma** is raised per client (USD/EUR/INR, one currency per invoice; foreign clients no GST, Indian clients 18% GST on Professional Fees only) → client pays (bank / PayPal / Skydo / Razorpay) → after full payment and approval a **tax invoice** is generated, matter folders are created, paralegal notified. **Credit / Debit notes** against tax invoices. **Expenses**: foreign law firms, patent offices, Indian purchases (GST/TDS), owner card spends.

## Four fixed roles – single source of truth: `app/Services/PlatformAccess.php` (`matrix()`)
| Role | Allowed | Never |
|---|---|---|
| Accountant | everything | – |
| Accountant Assistant | create/finalise proforma, request tax approval, generate APPROVED tax invoice, credit/debit notes, expenses, masters, Word upload | see or change any payment / payment status, approve |
| Director | view all invoices + expenses, record/revert payments, write-off short payment, mark bills paid/unpaid, approve tax invoices + paralegal requests, dashboards, reports | create/edit invoices, notes, expenses |
| Paralegal | view proforma, tax invoices, law-firm + patent-office expenses; raise requests ("client initiated payment", "please pay this firm") | change anything |
Always check permissions with `$user->allowed('perm.name')`, never with role names in new code. Requests/approvals live in `app/Services/PortalWorkflow.php`.

## Key code
- Invoices: `InvoiceService` (draft, send, convert currency, `orderTax`, FY numbering `nextNumber`), `PdfService` + `resources/views/pdf/invoice.blade.php` (carry-forward pages, footer only on last page, GSTIN / Place of Supply / LUT line), `InvoiceFormat` (combined / detailed / india_gst)
- Payments: `PaymentService` (receipt + TDS + bank charges, `writeOff`, `revert`); `Invoice::settledAmount()` = received + TDS + write-off
- GST helpers: `app/Services/Gst.php` (state codes, intra-state → CGST+SGST else IGST, export LUT text)
- Notes: `NoteService` + `pdf/note.blade.php` (CN/DN numbers per FY, GST split)
- Reports: `ReportService` (ageing, monthly, GSTR-1 working) → `/crm/reports/gstr1?month=YYYY-MM`, `/crm/reports/ageing`
- Dashboards per role: `DashboardService` + `resources/views/filament/pages/workspace.blade.php`
- Word import `WordService`, master Excel import `MasterImportService`, vendor PDF reader `resources/reader/read_invoice.py`
- UI theme CSS: `resources/views/filament/parts/font.blade.php` (global) and `style.blade.php` (crm-* classes)
- Daily reminders: `crm:daily-checks` (15-day proforma follow-up, vendor due, reimbursements)

## Gotchas (learned the hard way)
- Filament injects closure params **by name**: use `$record`, `$state`, `$query`, `$data`, `$get`, `$set` – a closure like `fn (Builder $q)` or `fn ($state, $r)` gets a wrong/empty value and crashes (e.g. "newQueryWithoutRelationships() on null").
- Money is string + bcmath (`App\Services\Money`), never floats. Never add amounts of different currencies together.
- Numbering is gap-free per series & financial year (`number_sequences` table, row lock).
- Every schema change = new migration; never edit old ones (live SQLite DB on the owner's Windows PC is migrated by `START_CRM.cmd`).
- `.env`, `*.sqlite`, `storage/app/private`, uploaded PDFs never go to git.

## Run locally
```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan filament:assets          # publishes public/css|js/filament (not in git)
php artisan serve                    # http://127.0.0.1:8000/admin  (first run → /setup creates the Accountant)
php artisan test --compact           # ~98 tests; the Windows OCR test only passes on Windows
```
On the owner's PC: `CRM\START_CRM.cmd` (backs up DB to `database/backups`, migrates, serves on :8000).

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
