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

## Daily Review Summary Cron

`cron/daily_review_summary.php` sends a per-client WhatsApp summary at
22:00 IST. Schedule it with crontab (server in IST):

```
0 22 * * *  /usr/bin/php /var/www/google-rev/cron/daily_review_summary.php
```

If your server is on UTC, use `30 16 * * *`. Logs are written to
`storage/logs/cron_daily_summary.log`.

## WhatsApp Gateway (bulk.akdwk.in)

Configured via Global Settings. Used by:

- Forgot Password OTP / reset link
- Admin "Send Test WhatsApp" tool
- Future notifications (`WhatsAppService::sendText` / `sendMedia`)

## Notes

- QR generation tries `phpqrcode` then falls back to api.qrserver.com.
- Standee uses TrueType fonts for big readable headings; drop a TTF into
  `public/lib/fonts/` if your server has no system fonts.
- Public review page footer is dynamic — Powered by + Helpline are
  driven by Global Settings.
