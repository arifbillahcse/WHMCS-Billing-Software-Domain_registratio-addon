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

Phases 0-4 done (skeleton; addon activation, tables, services; manualreg registrar module; admin dashboard; notifications). Dev smoke test: `cd tests && composer install && php smoke_test.php && php registrar_test.php && php admin_test.php && php notifier_test.php`. See PLAN.md for the roadmap.

## Notifications (Phase 4)

Configure under System Settings > Addon Modules > Nameserver Manager > Configure,
then use the **Notifications** tab in the addon to send a test message.

- **Admin alerts** (when a customer or the system queues a request; requests made by an
  admin are not announced): admin email, an internal support ticket (not linked to the
  customer), Telegram, Slack.
- **Customer emails** when a request is marked applied or failed (the failure reason you
  enter is included).
- A failing channel never blocks a customer's save; failures appear in the domain's audit
  history as `notify_failed` and in the Activity Log.
- Admin email uses WHMCS `SendAdminEmail` (type `system`), so it reaches staff whose role
  receives system emails.
