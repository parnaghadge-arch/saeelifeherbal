# Database schema changes — ALTER queries

Every schema-affecting query introduced (or discovered) by the checkout guest-flow work is
recorded here, per the project requirement that all ALTER queries live in a dedicated doc.

## Summary for the checkout guest-flow change

**No new tables or columns were introduced by the guest-flow work itself.** The flow reuses the
existing tables exactly as designed:

| Purpose | Table | Columns used |
|---|---|---|
| Partner registration (member + address) | `members`, `member_profiles` | as-is |
| Auto-linked customer login | `customers` | `member_id`, `password_hash`, `mobile`, `email` |
| Login throttling | `login_attempts` | `scope` (`customer` / `member`) |
| Referral click recording | `member_referral_clicks` | as-is |
| Order placement | `orders` | `customer_id`, `member_id`, `referral_member_id`, `created_by_member_id` |

One **pre-existing drift** matters for this flow and is documented below:
`orders.created_by_member_id` is written by `OrderService::checkout()` but is missing from
`database/schema.sql`.

## 1. `orders.created_by_member_id` (pre-existing drift)

* **Written by:** `lib/OrderService.php` (every storefront checkout), `lib/MemberPurchaseService.php`
  (admin/member assisted orders).
* **Read by:** `member/orders.php`, `member/order-details.php` (both guard with
  `db()->hasColumn('orders', 'created_by_member_id')`).
* **Declared in:** `lib/Schema.php` → `orders.columns` as `fk:members:setnull|null|index`
  (the runtime schema builder is the source of truth).
* **Missing from:** `database/schema.sql` (the bootstrap export lags the code).
* **Effect on databases restored from `database/schema.sql`:** the order INSERT fails with
  MySQL error 1054 *"Unknown column 'created_by_member_id'"*.

### Detection

```sql
SHOW COLUMNS FROM `orders` LIKE 'created_by_member_id';
-- empty result = the column is missing and the ALTERs below are required
```

### ALTER queries (MySQL / MariaDB)

Run in this order. Stop after query 1 if you only need checkout to stop failing; queries 2–3
bring the column to full parity with a fresh install (index + foreign key).

```sql
-- 1. Add the column (same as the automatic runtime repair; NULLable, no FK).
ALTER TABLE `orders`
  ADD COLUMN `created_by_member_id` BIGINT UNSIGNED NULL AFTER `referral_member_id`;

-- 2. Index (member "orders I placed for my team" listings depend on it for speed).
ALTER TABLE `orders`
  ADD KEY `idx_orders_created_by_member_id` (`created_by_member_id`);

-- 3. Foreign key, matching the fk_orders_* naming used in database/schema.sql.
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_created_by_member_id`
  FOREIGN KEY (`created_by_member_id`) REFERENCES `members` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;
```

Notes:

* MySQL before 8.0.29 / MariaDB before 10.5 have no `ADD COLUMN IF NOT EXISTS`; use the
  detection query first and skip query 1 if the column already exists.
* If query 3 fails because legacy rows hold orphan values (a member id that no longer exists),
  clean them first: `UPDATE orders SET created_by_member_id = NULL WHERE created_by_member_id IS NOT NULL AND created_by_member_id NOT IN (SELECT id FROM members);`
* Column type must be `BIGINT UNSIGNED NULL` to match `members.id`.

### What the app does automatically

* `lib/Schema.php` `ensureColumns()` adds any column declared in the schema but absent from a
  live table (used by the installer/upgrade path). The repaired column is deliberately bare:
  **no foreign key and no index** (MySQL rejects `ADD COLUMN … REFERENCES` without a named
  constraint, and `NOT NULL` without default would fail on populated tables).
* `lib/OrderService.php` `checkout()` now self-heals `orders` once per request via
  `Schema::ensureColumns()` before writing, so checkout works even on a drifted database.
  This repair adds the column only — run queries 2–3 above for full parity.
* `member/orders.php` degrades gracefully (skips the "assisted" column) when the column is
  missing, so the partner portal never fatals on a drifted database.

## 2. No other schema changes

The guest-flow decision log (register first → auto sign-in → review & pay at checkout; no
guest checkout) required **no further ALTER queries**. Guest accounts are no longer created at
checkout, so no customer-table changes are needed; partners and customers share credentials via
`customers.member_id`, which both `database/schema.sql` and `lib/Schema.php` already declare.

## Maintenance rule

`lib/Schema.php` is the schema source of truth and `database/schema.sql` is the bootstrap
export. Any future schema edit must be applied to **both**, and any query applied by hand to a
live database must be appended to this document.
