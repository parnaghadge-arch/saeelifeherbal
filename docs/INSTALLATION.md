# Saee Life Herbal — Installation & Deployment Guide

Core PHP 8.x + MySQL 8.x (PDO). No framework, no Composer requirement, no build step.

---

## 1. Requirements

| Component | Minimum |
| --- | --- |
| PHP | 8.1+ (8.2/8.3 recommended) |
| Extensions | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `curl`, `json`, `zip`, `bcmath` |
| MySQL | 8.0+ (MariaDB 10.6+ works) |
| Web server | Apache 2.4 with `mod_rewrite`, or nginx + php-fpm |
| HTTPS | Required in production (Razorpay, secure cookies, PWA) |

Local development can use SQLite (`storage/db/app.sqlite`) — no configuration needed.

---

## 2. Upload & permissions

```bash
# copy the application (excluding .git) to the document root
sudo chown -R www-data:www-data /var/www/saeelifeherbal
sudo find /var/www/saeelifeherbal -type d -exec chmod 755 {} \;
sudo find /var/www/saeelifeherbal -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/saeelifeherbal/storage
```

`storage/` must be writable (database, logs, uploads, backups, private KYC documents) and
must never be served directly — `.htaccess` and the sample nginx config both block it.

---

## 3. Create the database

If the database does not exist yet, the installer creates it automatically (the MySQL user
must have the `CREATE` privilege — `root` on XAMPP does). Otherwise create it manually:

```sql
CREATE DATABASE saee_life_herbal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'saee'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON saee_life_herbal.* TO 'saee'@'localhost';
FLUSH PRIVILEGES;
```

---

## 4. Configure environment

```bash
cp .env.example .env
nano .env
```

Key values:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=saee_life_herbal
DB_USERNAME=saee
DB_PASSWORD=a-strong-password

APP_KEY=<64 random hex chars>      # php -r "echo bin2hex(random_bytes(32));"
SESSION_SECURE=true

# Razorpay (leave blank to keep the gateway disabled)
RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=
RAZORPAY_WEBHOOK_SECRET=
```

> `APP_KEY` encrypts KYC numbers and bank account numbers with AES-256-GCM. Changing it
> after data exists will make previously encrypted values unreadable — set it once and
> back it up with the database.

---

## 5. Install the schema and seed the admin account

### Windows / XAMPP quick path

```powershell
cd C:\xampp\htdocs\saeelifeherbal
copy .env.example .env
# edit .env -> DB_PASSWORD= (blank on XAMPP), DB_DATABASE=saeelifeherbal
C:\xampp\php\php.exe tools\console.php doctor
C:\xampp\php\php.exe tools\console.php install --admin-user=admin --admin-pass="Admin@12345"
```

`doctor` prints one line per check (PHP version, extensions, PDO driver, folder permissions,
database connectivity) so a failure is never a blank "Something went wrong":

```
  [ OK ]   PHP version — 8.2.12
  [ OK ]   Extension pdo_mysql
  [FAIL]  Database connection — MySQL is not running … start MySQL in the XAMPP panel
```

Then open `http://localhost/saeelifeherbal/` and `http://localhost/saeelifeherbal/admin/login.php`.

> PHP **8.1 or newer** is required — XAMPP bundles older PHP in some installers. The app shows an
> explicit "Unsupported PHP version" page (and a CLI message) instead of a generic 500.
> Any database error on the web front now renders a "Database unavailable" page that names the
> driver and the check to perform; set `SAEE_DEBUG=1` in `.env` to see the raw error.

### Linux / macOS / production with MySQL

```bash
php tools/console.php doctor      # verify environment + connection first
php tools/console.php install
```

This creates all 62 tables, the 74 default settings, 42 permissions across 6 roles, the
royalty slabs, legal pages, navigation and notification templates, and a super admin.

Output includes the generated admin credentials:

```
Admin username: admin
Admin password: <printed once>
```

Change the password at **Admin → My Account** immediately after the first login at
`https://example.com/admin/login.php`.

Optional (development/testing only — never on production):

```bash
php tools/console.php demo       # realistic demo members, products, orders, commissions
```

Other console commands:

| Command | Purpose |
| --- | --- |
| `php tools/console.php doctor` | Environment/database/folder preflight, safe to run any time |
| `php tools/console.php schema:mysql` | Print the MySQL DDL |
| `php tools/console.php schema:sqlite` | Print the SQLite DDL |
| `php tools/console.php commissions:process` | Generate commission for eligible paid orders |
| `php tools/console.php royalty:run` | Evaluate royalty milestones |
| `php tools/console.php backups:run` | Create a database backup |
| `php tools/console.php sitemap` | Regenerate `storage/sitemap.xml` |

---

## 6. Scheduled jobs (cron)

```cron
*/10 * * * * cd /var/www/saeelifeherbal && php tools/console.php commissions:process >> storage/logs/cron.log 2>&1
0 1 * * *    cd /var/www/saeelifeherbal && php tools/console.php royalty:run >> storage/logs/cron.log 2>&1
0 2 * * *    cd /var/www/saeelifeherbal && php tools/console.php backups:run >> storage/logs/cron.log 2>&1
30 3 * * *   cd /var/www/saeelifeherbal && php tools/console.php sitemap >> storage/logs/cron.log 2>&1
```

---

## 7. Payment gateway (Razorpay)

1. Admin → **Settings → Payment Gateways** → enter Key ID, Key Secret and Webhook Secret,
   or set them in `.env`.
2. Razorpay Dashboard → Settings → Webhooks → add
   `https://example.com/api/orders/razorpay-webhook.php` with events
   `payment.captured`, `payment.failed`, `order.paid`.
3. Webhook signatures are verified server-side; duplicate events are ignored through the
   `gateway_webhook_events` table, and commission is generated idempotently.

Cash on Delivery, Bank Transfer and UPI (manual verification) are enabled from the same
screen without any gateway account.

---

## 8. Clean URLs, sub-directory installs and branding

**Every page has a clean URL.** `/product-categories` and `/product-categories.php` serve
exactly the same page, and every internal link is generated without the `.php` extension. This
works unchanged whether the site sits at `http://localhost/` or at
`http://localhost/LIVEpro/SaeeLifeHerbal-1/`.

How it works:

| Piece | Role |
| --- | --- |
| `config/routes.php` | The single route table: public slug → script (`'about-us' => '/about.php'`). Pure data, no side effects. |
| `includes/router.php` | Resolves a request path to a script and dispatches it. Detects the install sub-directory from `DOCUMENT_ROOT`. |
| `.htaccess` | Apache: real files and app directories are served as-is, everything else goes to `index.php`. |
| `nginx.conf.example` | nginx equivalent (`try_files $uri $uri/ /index.php?$query_string`). |
| `router.php` | PHP built-in server (`php -S 0.0.0.0:8000 router.php`) for local development. |

Rules the resolver applies, in order:

1. A real directory with its own `index.php` (`/admin`, `/member`, `/api`, `/pwa`) → that index.
2. A real `.php` file → that file.
3. Route table alias → `/about-us` → `about.php`; `/product/<slug>` → product page;
   `/blog/<slug>` → blog post; `/member/<page>` → `member/<page>.php`.
4. A script without its extension → `/about` → `about.php`.
5. Otherwise the CMS page renderer, which shows a branded 404 when the slug is unknown.

**Branding comes from the database.** Admin → **Settings → Logos & Branding** controls
`logo_header` (image), `logo_text` (main line), `logo_subtitle` (line under it), plus
`logo_footer`, `logo_admin`, `logo_pwa`, `favicon` and the theme colours. Nothing in the header
is hardcoded: the logo image and both text lines are rendered from those settings and shown at
every width (the subtitle is hidden only below 768 px, where space is tight).

Diagnostics: `php tools/console.php doctor` prints the detected base path and checks that the
main slugs resolve to real scripts.

Sub-directory troubleshooting:

| Symptom | Fix |
| --- | --- |
| **A page loads as plain unstyled HTML (CSS/JS "blocked")** | This happened when the page was opened through https but the links were written as `http://…` — the browser refuses mixed content. Application URLs are now origin-relative (`/assets/css/app.css`), so the scheme and host always follow the page. If you upgraded from an older copy, hard-refresh once (the asset version is `?v=1.1.0`, the service-worker cache is `saee-v1.1.0`). |
| Styles look stale after an update | Bump `APP_VERSION` in `config/app.php`; asset URLs and the service-worker cache name derive from it. |
| `/LIVEpro/SaeeLifeHerbal-1/about-us` shows the home page | The rewrite is not reaching `index.php`. Confirm `mod_rewrite` is enabled and `AllowOverride All` is set for `htdocs`, or set `RewriteBase /LIVEpro/SaeeLifeHerbal-1/` in `.htaccess`. |
| Every page is styled but links point at the domain root | Copy your real domain (with `/LIVEpro/SaeeLifeHerbal-1`) into `APP_URL` in `.env`; otherwise the base path is auto-detected from the request. |
| Admin links jump to the public site | You are on an old copy — the router now derives the base from `DOCUMENT_ROOT`, which is identical for the front controller and the admin/member apps. |

---

## 9. PWA

* Manifest: `/manifest.json` (admin-editable app name, colours, icons in **Settings → PWA**).
* Service worker: `/service-worker.js` — caches only the public app shell and static
  assets. It never caches admin/member pages, API responses, KYC, bank or financial data.
* Offline fallback: `/offline`.

---

## 10. MLM activation checklist

The compensation plan ships **disabled for public display** (`mlm_plan_public = 0`) on
purpose. Before activating:

- [ ] Written legal opinion on the unilevel plan under the Indian Direct Selling /
      Prize Chits & Money Circulation Schemes (Banning) Act and state rules.
- [ ] GST registration and tax treatment of commissions confirmed; TDS section configured
      in **MLM Settings → Payout policy**.
- [ ] Commission rates per product approved by the company (Admin → Products → MLM).
- [ ] Payout bank account, minimum withdrawal, deduction policy and processing SLA set.
- [ ] Income disclaimer visible on the opportunity page and in the partner agreement.
- [ ] Enable with **MLM Settings → Compensation plan active** and
      **Show the business opportunity publicly**.

Per the compliance rules baked into the engine: commissions come only from *paid product
orders*, never from recruitment or joining fees; the company controls all rates; the plan is
unilevel with unlimited direct referrals (binary is not supported); commissions are reversed
(never deleted) on refund/cancellation.

---

## 11. Backups & disaster recovery

* Automated SQL dumps land in `storage/backups/` (excluded from the web root).
* Copy `storage/backups/`, `storage/uploads/`, `storage/private/`, `.env` and `APP_KEY`
  off-site daily.
* Restore procedure: create an empty database → `mysql db < backup.sql` → restore the
  upload folders → verify `APP_KEY` matches → log in as admin.

---

## 12. Troubleshooting

| Symptom | Fix |
| --- | --- |
| 500 on every page | Check `storage/logs/`, set `APP_DEBUG=true` temporarily |
| "Security token expired" on every form | Session directory not writable; check `session.save_path` |
| Images not showing | `storage/uploads` permissions, or wrong `APP_URL` |
| Razorpay callback fails signature | Key secret / webhook secret mismatch |
| Commission not generated | Order must be **paid**; check cron and `commission_status` |
| Payout blocked | KYC/bank must be approved when `require_kyc` / `require_verified_bank` are on |
| CSS/JS refused: `MIME type ('text/html')`, page renders unstyled | The asset files are missing (usually an incomplete upload or a clone made before assets/vendor was committed), so Apache serves its own 404/403 HTML page for `/assets/...`. Run `php tools/console.php doctor` — the **Front-end assets** block names the missing file. `.htaccess` also forces the correct MIME types via `mod_mime`. |
| `manifest.json` returns 403 | Fixed: `.htaccess` used to deny every `.json`. Only `composer.json`/`package.json` are blocked now and `manifest.json` is explicitly granted. On Apache, confirm `AllowOverride All` is set for the vhost. |
| Console prints a "Something went wrong" | Fixed in 1.0: the CLI now prints the real error. Run `php tools/console.php doctor`. |
| `Unsupported PHP version` page | The server runs PHP < 8.1. Point Apache/CLI at PHP 8.1+ (XAMPP: Config → PHP 8.x). |
| `Database unavailable` page | MySQL is stopped, the database is missing, or `.env` credentials are wrong. `doctor` pinpoints which. |
| `errno 1824 Failed to open the referenced table` (MySQL) | Table creation order; fixed in 1.0 — `Schema::install()` disables FK checks while creating tables. Ensure you run the bundled code, not the SQL paste. |
| Re-running install fails on `admin_roles.slug` / `admin_users.username` | Fixed in 1.0: install is idempotent, existing roles/permissions are reused and the admin row is updated. |
| `SQLSTATE[HY093]: Invalid parameter number` on MySQL | A query reused a named placeholder (`... LIKE :q OR ... LIKE :q`). PDO expands each occurrence to its own slot, so MySQL rejects it while SQLite tolerates it. Fixed in 1.0: the DB layer rewrites repeats to unique names before preparing. |
| Need to audit SQL binding (development) | `SAEE_STRICT_BIND=1` makes unused/mismatched bind parameters fail loudly, and every incident is appended to `storage/logs/database-error.log`. |
