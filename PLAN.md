# Plan: Manual Registrar + Nameserver Manager

Target: WHMCS 9.6, PHP 8.2. Develop and test on a staging install, never production.

## Goal
Customers change nameservers in the WHMCS client area for domains held at
external providers. The change is stored as a request, admins are notified and
apply it manually at the provider, then mark it applied.

## Architecture
```
Client area -> manualreg (registrar module) -> RequestService -> DB + audit + Notifier
Admin area  -> nsmanager (addon module)     -> RequestService -> DB + audit + Notifier
includes/hooks/nsmanager.php: daily reminders, client banner, DNS auto-verify
```
Shared code lives in `modules/addons/nsmanager/lib/`; the registrar module
`require_once`s it. All state changes go through `RequestService`.

## Repo layout
```
modules/registrars/manualreg/manualreg.php
modules/addons/nsmanager/nsmanager.php
modules/addons/nsmanager/hooks.php            (loaded by includes/hooks/nsmanager.php)
modules/addons/nsmanager/lib/{Repository,Validator,RequestService,Notifier}.php
modules/addons/nsmanager/templates/
modules/addons/nsmanager/lang/english.php
includes/hooks/nsmanager.php
```

## Request statuses
`pending`, `applied`, `failed`, `cancelled`, `superseded`

Rules: one live `pending` request per domain (a new one supersedes the old);
identical-to-current NS is a no-op; DNS mismatch during auto-verify means keep
waiting, never `failed`.

## Database (created in addon `_activate`, via Capsule)
- `mod_nsmanager_requests`: id, domain_id, domain, client_id, old_ns (json),
  new_ns (json), status, requested_by, requester_ip, admin_note, resolved_by,
  resolved_at, last_reminded_at, created_at, updated_at
- `mod_nsmanager_domains`: domain_id (unique), provider, provider_login_url,
  account_label, notes, applied_ns (json: NS currently live at provider)
- `mod_nsmanager_log`: id, domain_id, request_id, event, actor, details, created_at
  (append-only audit trail)

## Classes
- `Repository`: all Capsule queries
- `Validator`: min 2 NS, max 5, hostname format, no duplicates
- `RequestService`: createRequest, markApplied, markFailed, cancel; writes audit rows
- `Notifier`: drivers for admin email, ticket, Telegram, Slack, customer email;
  every driver wrapped in try/catch so failures never break a customer save

## Decisions
- WHMCS 9.6 / PHP 8.2
- `GetNameservers` returns the latest requested NS while a request is pending,
  otherwise the applied NS
- Notification channels (all toggleable in addon settings): admin email, ticket,
  Telegram, Slack
- Auto-verify via `dns_get_record($domain, DNS_NS)` in the daily cron: included
- Decided (Phase 3): `failed` shows the customer the live (applied) nameservers again, since no request is pending any more; the failure reason is stored in `admin_note`
- To verify against WHMCS 9.x docs before use: client-area banner hook and template
  variable names, `localAPI` parameters, addon permission checks, CSRF handling

## Phases
| # | Scope | Done when |
|---|-------|-----------|
| 0 | Skeleton, PLAN, README, .gitignore | files exist |
| 1 | Addon `_config/_activate/_deactivate/_upgrade`, tables, Repository, Validator, RequestService, audit | activation creates tables; a script can create and resolve a request |
| 2 | `manualreg` Get/SaveNameservers, stub Register/Transfer/Renew | client NS change creates a pending row and audit entry |
| 3 | Admin dashboard: tabs, apply/fail/cancel, bulk apply, provider tab, domain history | resolve a request from admin; audit shows it |
| 4 | Notifier drivers, settings, customer email template | customer change fires channels; Mark applied emails customer |
| 5 | Daily reminder digest, client banner, DNS auto-verify, rate limit, glue warning, escaping, docs | 24h reminder fires; request auto-closes after provider update |
