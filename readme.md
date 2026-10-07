# WHMCS Manual Registrar + Nameserver Manager

Lets customers update nameservers for domains that are bought at external
providers and are **not** connected to any WHMCS registrar API.

- **Registrar module** `manualreg`: customer-facing. Saving nameservers creates a
  `pending` request instead of calling a provider API.
- **Addon module** `nsmanager`: admin dashboard with a pending queue, audit
  history, notifications (email, ticket, Telegram, Slack) and reminders.

Targets: **WHMCS 9.6**, **PHP 8.2**.

See [PLAN.md](PLAN.md) for the design and build phases.

## Install (available after Phase 1-2)

1. Copy `modules/` and `includes/` into your WHMCS root.
2. Admin > System Settings > Addon Modules > activate **Nameserver Manager**.
3. Admin > System Settings > Domain Registrars > activate **Manual Registrar**.
4. Set a domain's registrar to **Manual Registrar**.

## Status

Phases 0-3 done (skeleton; addon activation, tables, services; manualreg registrar module; admin dashboard). Dev smoke test: `cd tests && composer install && php smoke_test.php && php registrar_test.php && php admin_test.php`. See PLAN.md for the roadmap.
