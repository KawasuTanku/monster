# Monster — Energy-Drink P&L Tracker

A small, login-protected **profit & loss tracker** for a side business selling
energy drinks. Hosted at **monster.kawasu.wtf** behind FrankenPHP (Caddy).

## What it does

- **Login-protected** single-owner account (bcrypt-hashed password, session cookies).
- Record **sales** (money in) and **expenses** (money out) with date, category, note.
- **Dashboard** with revenue / expenses / net profit and a per-category breakdown.
- **Report** view of every entry.
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
│   ├── Auth.php         # session auth + hashed credentials
│   ├── helpers.php      # e()/money()/csrf*/flash helpers
│   └── views/           # login, dashboard, transactions, report, settings, layout
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

On first load, visit the site and create the owner account. Done.

## Security notes

- Passwords hashed with `PASSWORD_BCRYPT` (cost 13).
- Session cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` when behind HTTPS.
- All state-changing forms carry a CSRF token verified server-side.
- `data/db.json` lives outside the document root, so it is never web-served.
