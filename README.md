# Smart Auto-Review Generation & Gating System

Core PHP + MySQL + AJAX implementation scaffold generated into this folder.

## Project Root

- `app/` application code (config, controllers, services, helpers, views)
- `public/` web root (set this as your AAPanel site directory)
- `database/` schema + seed SQL
- `cron/` buffer refill script

## Quick Start

1. Import `database/schema.sql`
2. Import `database/seed.sql`
3. Run every file in `database/migrations/` in date order
4. Update DB credentials in `app/config/database.php`
5. Update `APP_URL` in `app/config/config.php`
6. Set document root to `public/`
7. Create writable folders: `public/uploads/qr`, `public/uploads/standee`, `public/uploads/standee/generated`, `public/uploads/standee_templates`, `public/uploads/settings`, `public/uploads/sliders`
8. Open `public/register.php`

## Routes

- `register.php`
- `login.php`
- `forgot_password.php` — request OTP via WhatsApp
- `reset_password.php?token=...` — set new password
- `dashboard.php`
- `client_settings.php`
- `client_wallet.php` — Wallet history (sign-up bonus, top-ups, deductions)
- `standee_gallery.php` — Pick an admin-uploaded template and generate a branded standee PNG
- `standee_download.php` — One-time download after generation (internal redirect target)
- `review.php?t=<token>`
- `super_admin.php`

## Admin Panel

- `super_admin.php` — Dashboard
- `admin_businesses.php` — Manage Businesses
- `admin_wallet.php?client_id=ID` — Per-client wallet ledger + top-up
- `admin_category_facilities.php` — Categories & Facilities (full CRUD incl. delete)
- `admin_standee_templates.php` — Upload/manage blank standee background templates (PNG/JPG/WebP)
- `admin_reports.php` — Reports module (Today / This Week / This Month / Last 3 Months / Custom)
  - `?format=print&preset=...` opens the print-optimized version (browser → Save As PDF)
- `admin_global_settings.php` — Global Settings (incl. Sign-up Bonus, Price per Review) + WhatsApp gateway + Helpline
- `admin_audit.php` — Audit & Login Logs

The admin panel uses a unified persistent sidebar layout
(`app/views/admin/partials/layout_head.php` /
`layout_foot.php`) so menus stay visible across all pages. The client
panel mirrors the same structure via
`app/views/client/partials/layout_head.php` for a cohesive UI.

## Wallet & Sign-up Bonus

The system now runs on a pure wallet/credit model — there is no quota.

- Set **Price per Review** and **Sign-up Bonus Amount** in Global Settings.
- New registrations are auto-credited the sign-up bonus, which is logged
  in `wallet_transactions` (source `signup_bonus`).
- Every 5-star Google redirect deducts `price_per_review` credits and
  writes a `review_deduction` ledger entry.
- Admin top-ups & deductions write `admin_topup` / `admin_deduct` entries
  with the responsible admin id captured.
- All movements are visible to the client at `/client_wallet.php` and to
  the admin at `/admin_wallet.php?client_id=ID`.

## Centralized Cron Scheduler

The application needs exactly **one** server cron, running every minute:

```
* * * * *  /usr/bin/php /path/to/project/cron/master.php >> /dev/null 2>&1
```

No CLI cron on the host? Hit the HTTP fallback every minute instead:
`/run_cron.php?key=<REFILL_CRON_SECRET>`.

The master tick executes every registered background task when due —
AI buffer refill, daily review summary report, subscription/payment-due
reminders, wallet low-balance reminders, the WhatsApp/email notification
queue, automatic backups and housekeeping. Everything is managed from
**Admin → Cron Settings**: status, last/next run, execution history with
per-run logs, enable/disable, Run Now, retry failed runs and master-cron
health monitoring. One-time install button creates `cron_jobs`,
`cron_job_runs` and `notification_queue` (or run
`database/migrations/2026_08_05_centralized_cron_scheduler.sql`).

**Adding a new scheduled task** (no new server cron, ever):

1. Create `app/cron_jobs/<Name>Job.php` with
   `run(PDO $pdo, CronLogger $log): string`.
2. Register it in `app/cron_jobs/registry.php` (key, class, schedule).

MySQL `GET_LOCK` guards the master tick and each job, so overlapping
ticks and duplicate executions are impossible. Runs are logged to
`cron_job_runs` and `storage/logs/cron_master.log`.

Queue messages (reminders, follow-ups, scheduled notifications) go
through `queueWhatsAppMessage()` in
`app/helpers/notification_queue_helper.php` — delivery, retries with
backoff and dedupe keys are handled by the queue job.

Legacy entries `cron/daily_review_summary.php` and
`cron/refill_buffer.php` still work but simply delegate to the
scheduler (the forced buffer-reset tool
`run_cron.php?key=…&force_reset=true[&client_id=N]` keeps its original
behaviour). Old per-task crontab lines can be removed.

## WhatsApp Gateway (bulk.akdwk.in)

Configured via Global Settings. Used by:

- Forgot Password OTP / reset link
- Admin "Send Test WhatsApp" tool
- Future notifications (`WhatsAppService::sendText` / `sendMedia`)

## SEO & Public Landing

- `public/index.php` carries full on-page SEO: meta title/description/
  keywords (override via `seo_title`, `seo_description`, `seo_keywords`
  settings), canonical, Open Graph/Twitter cards, geo tags and JSON-LD
  (SoftwareApplication + Organization + FAQPage).
- Visible FAQ section is driven by the `landing_faqs` JSON setting
  (defaults included); the promo strip by `landing_offer_enabled` /
  `landing_offer_text`.
- `public/robots.txt` + `public/sitemap.php` (submit the sitemap URL in
  Google Search Console).

## Location Offer (Devbhumi Dwarka FREE)

Registration captures City + District (Gujarat district dropdown;
migration `2026_08_05_client_city_district.sql`). Districts listed in
the `special_district_names` setting (default "Devbhumi Dwarka") get
`special_district_trial_days` free validity (default 1095 = 3 years)
instead of the standard `signup_subscription_trial_days`. Both editable
in Admin → Global Settings.

## GitHub Auto Update System

`admin_system_update.php` (Admin → System Update) deploys the app straight
from GitHub — no more ZIP uploads.

**One-time setup**

1. Open Admin → System Update and click **Install Update System** (creates
   `system_updates`, `system_update_backups`, `system_migrations` — or run
   `database/migrations/2026_08_04_github_auto_update_system.sql` manually).
2. Save the GitHub repository (`owner/name`), branch and a Personal Access
   Token with `repo` read access. The token is write-only (never displayed).

**How it works**

- **Check for Update** compares the recorded commit with the branch head and
  shows version, commit hash/message/author/date, changed files and release
  notes — or "Already Up To Date".
- **Update Now** runs automatically with a live progress bar:
  backup (code ZIP + DB dump) → download zipball → verify (zip-slip guard,
  required files, `php -l` syntax check) → deploy (atomic per-file swap) →
  run new `database/migrations/*.sql` → finalize (permissions, OPcache +
  cache clear, version record).
- **Safety**: any failure after deploy triggers an automatic rollback of both
  code and database from the pre-update backup. Manual rollback to any stored
  backup is available in Backup History.
- **Never touched** by update, backup or rollback: `.env`,
  `app/config/config.php`, `public/uploads/`, `storage/` (logs/backups),
  `public/.well-known/`.
- Version comes from the repo root `VERSION` file; update/rollback/backup
  history and per-step logs are stored in the DB and `storage/logs/updater.log`.
- Migrations already on disk are baselined as applied on first use; only
  migration files added by future updates are executed (tracked in
  `system_migrations`).

## Notes

- QR generation tries `phpqrcode` then falls back to api.qrserver.com.
- Standee uses TrueType fonts for big readable headings; drop a TTF into
  `public/lib/fonts/` if your server has no system fonts.
- Public review page footer is dynamic — Powered by + Helpline are
  driven by Global Settings.
