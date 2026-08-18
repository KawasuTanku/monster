# Monster — Energy-Drink P&L Tracker

A small, login-protected **profit & loss tracker** for a side business selling
energy drinks. Hosted at **monster.kawasu.wtf** behind FrankenPHP (Caddy).

## What it does

- **Login-protected, multi-user** (hashed passwords, session cookies).
  The first account created during setup is an **admin**; admins can add
  co-workers as **members**. All users share the same transaction ledger.
- Record **sales** (money in) and **expenses** (money out) with date, category, note.
- **Dashboard** with revenue / expenses / net profit and a per-category breakdown.
- **Report** view of every entry, with filtering and CSV export.
- **Inventory** tracking per can: stock levels, unit cost/price, reorder
  thresholds (low-stock flagged), and total capital tied up in stock.
- Edit / delete entries, change password, reset all data.

## Architecture

```
monster/
├── web/
│   ├── index.php        # front controller + router (FrankenPHP php_server)
│   └── assets/style.css # dark UI
├── src/
│   ├── App.php          # wiring + data-dir resolution
│   ├── Storage.php      # atomic JSON-file storage (no DB dependency)
│   ├── Transaction.php  # domain object (sale/expense, signed amounts)
│   ├── TransactionRepository.php
│   ├── Auth.php         # multi-user session auth, roles, hashed passwords
│   ├── helpers.php      # e()/money()/csrf*/flash helpers
│   └── views/           # login, dashboard, transactions, report, settings, users, layout
├── data/db.json         # <-- created at runtime (OUTSIDE doc root, not web-served)
├── composer.json
├── smoke-test.php       # headless end-to-end test
└── deploy.sh            # pull + install into the Caddy doc root
```

### Why JSON storage, not a database?

FrankenPHP's embedded PHP on this host has **no PDO / sqlite / mysqli** modules,
and MariaDB isn't reachable from the runtime. For a small side business, a single
atomic JSON file is plenty, and the `Storage` class is the only thing that knows
data lives on disk — swapping in a real database later is a localized change.

## Running locally

```bash
composer install
php -S 127.0.0.1:8000 web/index.php
# open http://127.0.0.1:8000  -> first visit triggers account setup
```

## Tests

```bash
php smoke-test.php   # boots php -S, drives the full flow with curl, asserts markers
```

## Deploying

The site is served from `/opt/caddy/monster.kawasu.wtf/web` (owned by `frankenphp`).
Run on the server as root / via sudo:

```bash
sudo ./deploy.sh
```

On first load, visit `https://monster.kawasu.wtf/setup` and create the admin
account. After that, admins can add co-workers via **Users** in the nav.

## Users & roles

- **Admin** — full access, plus user management (create / change role / remove).
  The first account (created at `/setup`) is always an admin, and the **last
  admin can never be demoted or deleted** (guards against locking everyone out).
- **Member** — can view the dashboard/report and record transactions, but cannot
  manage users.

A legacy single-owner install (credentials stored under `settings`) is migrated
into the `users` collection automatically on first run, with the original owner
promoted to admin — no manual step required.

## Security notes

- Passwords are stored fully hashed (never plaintext); verification uses a constant-time check.
- Session cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` when behind HTTPS.
- All state-changing forms carry a CSRF token verified server-side.
- The `/users` management area is admin-only (403 for members).
- `data/db.json` lives outside the document root, so it is never web-served.
