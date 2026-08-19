# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-19

First tagged release of the Monster P&L tracker — a login-protected,
FrankenPHP-served profit & loss tracker.

### Added
- Login-protected P&L tracker with multi-user support (admin/member roles),
  user management, and admin password reset.
- Transaction tracking (sales/expenses) with category, note, and inventory linking.
- Inventory tracking: per-can stock, low-stock flags, adjust, and linked
  transactions that auto-decrement/increment stock and log COGS.
- ROI dashboard: monthly series with inline SVG chart, overall ROI, and a
  monthly breakdown table.
- Transaction filtering + CSV export of the filtered set.
- Backup/restore: manual + daily snapshots, download, and admin restore
  (portable JSON dumps of the store).
- Admin backup deletion on the backups page.
- Tier 1 hardening: session idle (30m) and absolute (8h) timeout, plus
  security headers (HSTS, X-Content-Type-Options, X-Frame-Options,
  Referrer-Policy, Content-Security-Policy) on every response.
- Tier 2 features:
  - Transaction search (note/category) + pagination on /transactions.
  - "Duplicate" action on each transaction to clone it as a new entry dated
    today (inventory linkage cleared so it does not re-touch stock).
  - Per-category monthly **budget vs actual** section on the report, with
    under/over variance; budgets configured in Settings (admin only).
- Login rate-limiting: account/IP lock for 15m after 5 failed attempts.
- Browser page-title suffix changed from "Monster" to "M P&L".

### Changed
- Storage migrated from a JSON file to SQLite (drop-in via the Storage seam;
  legacy JSON auto-imported on first boot).
- Daily backup flooding fixed by splitting `create()` from `createDaily()`
  so a daily snapshot is created at most once per day.
- Report streamlined: removed the standalone "Transactions" stat card and
  added a per-month "Txns" count column to the monthly breakdown.
- UI polish: trash-can and key icons rendered as reliable SVGs/emoji with
  proper contrast; bare red "del" buttons replaced.

### Fixed
- Edit link on transactions (no stray /transactions/edit route); isAdmin
  injected into the layout via view().
- /backup 500 (round() size cast) and un-themed 500 from a broken docblock.
- Data-loss prevention: data/ untracked from git; deploy.sh snapshots/restores
  data/ so a deploy checkout can never clobber the live store.
- Deploy reliability: composer resolved from /opt/caddy/bin/composer.phar,
  FrankenPHP restarted after sync to clear opcode cache, HTTPS-on-8443
  Caddyfile example with internal TLS.

[0.1.0]: https://github.com/KawasuTanku/monster/releases/tag/v0.1.0
