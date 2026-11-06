# Saee Life Herbal — Website, E‑commerce & Partner (MLM) Platform

A complete, self-hosted business platform for **Saee Life Herbal** in plain PHP 8 — public
website, product e-commerce with online/COD checkout, a partner (unilevel) portal with
commissions, royalties and payouts, and a full admin back office.

No framework, no Composer, no build step. Upload, run the installer, done.

---

## Highlights

**Public website**
- Home page with hero slider, categories, featured products, gallery, testimonials, videos, FAQs, stats and CTAs
- Catalogue: categories → products → product details with variants, media gallery, related products
- Agriculture solutions, blog (categories, archive), gallery, video wall, FAQ accordions, contact/enquiry form, newsletter
- Branded content pages (About, Business Opportunity, Privacy, Terms, Refund, Shipping, Disclaimer)
- SEO built in: per-page meta + Open Graph, JSON-LD (Organization, Product, Breadcrumb, FAQ), `sitemap.php`, `robots.php`
- Clean, extension-less URLs (`/product-categories`, `/about-us`, `/product/<slug>`, `/blog/<slug>`) that also work with `.php`, at the domain root or in a sub-directory, driven by one route table (`config/routes.php`)
- Logo image, logo text and subtitle come from **Admin → Settings → Logos & Branding** — nothing in the header is hardcoded
- Responsive header from 320 px to ultrawide: brand (image + text) always visible, nav collapses to an app drawer below 1200 px, no overflow or overlap
- Mobile app feel: sticky translucent header, safe-area aware bottom tab bar (Home / Shop / Explore / Cart / Account), touch-sized controls, two-column product grid
- PWA: `service-worker.js`, `pwa/manifest.php`, offline page; installable on mobile
- Cart, wishlist, guest or logged-in checkout, Razorpay + Cash on Delivery, invoice PDF view, order tracking

**Partner portal** (`/member`)
- Dashboard with team, sales and earnings snapshot
- Referral links & sponsor lookup, referral downline view, team/genealogy tree
- Wallet ledger, commission statement, royalty programme, order history, statements
- KYC submission (encrypted PAN/Aadhaar, private document storage) and bank account management
- Payout requests with eligibility rules, notifications, support tickets, profile & password

**Admin back office** (`/admin`)
- Dashboard KPIs, order management with status timeline / manual payment capture / refunds
- Products, categories, media, variants, sliders, blogs & blog categories, gallery, videos, FAQs, testimonials, navigation, social links, pages
- Members, member details, KYC & bank verification queue with secure document viewer, customers, enquiries
- Commissions, commission rules, royalty approvals, genealogy, payouts + payout reports, wallets with adjustment ledger
- Reports (CSV/Excel/PDF), notification templates, admin users, roles & permissions, audit log, backups, settings, MLM settings

**Business logic**
- Unilevel commission engine (level 1 direct, level 2 grand-direct) with product-level rules, tax-excluded basis, hold periods, idempotent credit, reversals
- Wallet service with paise-accurate ledger, pending/approved/available projections, manual adjustments
- Royalty engine for achievement-based rewards, payout service with deductions (TDS/admin/processing) and proof uploads
- Sequence generator for order/invoice/payout codes, audit trail on every privileged action

**Security**
- Prepared statements everywhere, CSRF on every POST, output escaping helpers, password hashing with `password_hash`
- Role/permission model, login throttling, session hardening, security headers
- AES-256-GCM encryption for PAN/Aadhaar/account numbers (`Security::encrypt`, `enc:v1:` prefix)
- Private KYC/bank documents stored outside the web root and streamed only through an authorised endpoint
- Razorpay signature verification (checkout callback + webhook with de-duplication)

---

## Quick start (local)

```bash
# 1. requirements: PHP 8.1+ (pdo_sqlite or pdo_mysql, mbstring, openssl, fileinfo, gd)
git clone <repo> saeelifeherbal && cd saeelifeherbal

# 2. optional .env (copy and edit)
cp .env.example .env

# 3. check the environment (PHP version, extensions, folders, front-end assets, database)
php tools/console.php doctor

# 4. install schema + seed content (SQLite by default; set DB_* in .env for MySQL)
php tools/console.php install --admin-user=admin --admin-pass='Admin@12345'

# 5. load demo catalogue, members, orders, commissions
php tools/console.php demo

# 6. serve it
php -S 0.0.0.0:8000 router.php
```

Open <http://localhost:8000>. Sign in at `/admin/login.php` with `admin` / `Admin@12345`
(change it immediately). Demo partner logins use `Member@12345`.

For MySQL, create the database, set `DB_DRIVER=mysql` (+ credentials) in `.env`, then run
`php tools/console.php install` — see [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

### Console commands

| Command | Purpose |
| --- | --- |
| `php tools/console.php doctor` | Environment preflight: PHP version, extensions, PDO driver, folder permissions, front-end assets, DB connection |
| `php tools/console.php selftest` | Query-binding smoke test against the configured database |
| `php tools/console.php install` | Create all 62 tables, settings, roles/permissions, first admin (idempotent: safe to re-run, updates the admin password) |
| `php tools/console.php demo` | Seed demo catalogue, members, orders, commissions |
| `php tools/console.php schema:mysql` | Print the generated MySQL schema |
| `php tools/console.php schema:sqlite` | Print the generated SQLite schema |
| `php tools/console.php commissions:process` | Release matured commission holds |
| `php tools/console.php royalty:run` | Evaluate royalty achievements |
| `php tools/console.php backups:run` | Nightly database backup into `storage/backups` |
| `php tools/console.php sitemap` | Regenerate the cached sitemap |

`install` accepts `--admin-name`, `--admin-email`, `--admin-user`, `--admin-pass`, `--no-fresh`.

---

## Project structure

```
config/        app, constants, database configuration (env-aware)
includes/      bootstrap, .env loader, shared helpers, header/footer, partials
lib/           domain layer — Schema, Installer, Cart, OrderService, PaymentGateway,
               MlmCommissionEngine, WalletService, RoyaltyEngine, PayoutService,
               MemberService/TeamService, Security, Session, Validator, Url, Audit …
api/           JSON endpoints — cart, razorpay callback/webhook, wishlist, auth, sponsor lookup
admin/         back office (guarded by AdminAuth + permissions)
member/        partner portal (guarded by MemberAuth)
pwa/           manifest + offline shell
tools/console.php   CLI installer, seeder and cron jobs
database/      generated schema.sql
storage/       runtime data: sqlite db, logs, sessions, private documents, backups
docs/          installation & deployment guide
```

---

## Configuration

Everything can be supplied through `.env` (real environment variables win):

| Key | Default | Notes |
| --- | --- | --- |
| `SAEE_ENV` | `production` | `local` enables verbose errors |
| `SAEE_DEBUG` | `0` | `1` shows stack traces — never in production |
| `APP_URL` | auto-detected | e.g. `https://saeelifeherbal.com` |
| `DB_DRIVER` | `sqlite` | `sqlite` or `mysql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | MySQL connection |
| `SAEE_ENCRYPTION_KEY` | generated | used for AES-256-GCM document encryption — **back this up** |
| `SESSION_SECURE` | `0` | set to `1` behind HTTPS |

Runtime settings (site name, theme, Razorpay keys, MLM rules, payout limits, TDS…) live in the
admin **Settings** and **MLM settings** screens.

---

## Payments

Add `razorpay_key_id`, `razorpay_key_secret` and the webhook secret in admin → Settings, enable
the gateway, and point the Razorpay dashboard webhook to
`https://your-domain/api/orders/razorpay-webhook.php` for `payment.captured`,
`payment.failed` and `refund.processed`. Cash on Delivery is always available and can be capped
by amount. Payouts are manual (NEFT/UPI/bank) with UTR + proof capture in admin → Payouts.

---

## Compliance note

The partner programme is a product-purchase based unilevel plan: no joining fee, no reward for
recruitment alone, and income depends entirely on genuine product sales. A compliance notice is
stored in settings and shown on the Business Opportunity page. Review your state's direct-selling
rules and have the compensation plan legally reviewed before launch (admin → MLM settings tracks
this acknowledgement).

---

## Documentation

- [`docs/INSTALLATION.md`](docs/INSTALLATION.md) — server requirements, MySQL setup, permissions, cron, webhook, backups, troubleshooting
- `.env.example` — every supported environment key
- `.htaccess` / `nginx.conf.example` — Apache and nginx hardening + SEO rewrites
