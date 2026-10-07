# WHMCS Manual Registrar + Nameserver Manager

Lets customers update nameservers for domains bought at external providers that are
**not** connected to a WHMCS registrar API. A customer's change becomes a *pending
request*; you apply it at the provider and mark it applied (or let the DNS check close
it for you).

Targets **WHMCS 9.6**, **PHP 8.2**. Developed without access to a WHMCS install, so run
[docs/TESTING.md](docs/TESTING.md) on a staging copy before production.

## What is in the box

| Part | Path | Role |
|------|------|------|
| Registrar module `manualreg` | `modules/registrars/manualreg/` | Customer-facing: Get/SaveNameservers backed by the request queue |
| Addon `nsmanager` | `modules/addons/nsmanager/` | Admin dashboard, notifications, shared services |
| Hooks | `includes/hooks/nsmanager.php` | Loads the addon's cron and client-area hooks |

Features: pending queue with Applied/Failed/Cancelled history, bulk apply, per-domain
provider details and audit history, admin alerts (email, internal ticket, Telegram, Slack),
customer emails on applied/failed, daily reminder digest, "Update pending" banner for
customers, DNS auto-verify, per-domain rate limit, glue-record warning.

## Install

1. Copy `modules/` and `includes/` into your WHMCS root (merge, do not replace folders).
2. **System Settings > Addon Modules**: activate **Nameserver Manager**, set access
   control for the staff roles that may use it, then open **Configure** and set your
   notification channels.
3. **System Settings > Domain Registrars**: activate **Manual Registrar**.
4. For each external domain set the registrar to **Manual Registrar** (domain page in admin).
5. Open Addons > Nameserver Manager > **Notifications** and press *Send test notification*.
6. Open the domain in the dashboard (Providers tab > Edit) and enter the provider and the
   nameservers currently live there.
7. Make sure the WHMCS cron runs (`php -q crons/cron.php`) every 5 minutes: reminders and
   DNS auto-verify depend on it.

Activate the addon **before** switching a domain to Manual Registrar. Without the addon
tables, customers see a generic "temporarily unavailable" message and the real error is in
the Module Log.

## Upgrade

Copy the new files over the old ones, then open **Addon Modules** in the admin area once:
WHMCS runs the addon upgrade, which adds new columns (0.2.0 adds `last_checked_at`).
Until then the cron hooks stay idle on purpose. Data is never dropped on deactivate.

## How a request flows

1. Customer saves nameservers in the client area -> `pending` request, admins alerted.
   The customer sees "Update pending" on the domain page.
2. You change them at the provider (the queue has Copy buttons and the provider login link).
3. Either press **Mark applied**, or wait: every cron run (throttled to 15 min per request)
   looks up the domain's live NS and closes the request when they match. The customer gets
   an email either way.
4. If you cannot do it, press **Failed** and give a reason; it is emailed to the customer
   and shown on their domain page for 7 days. The customer's displayed nameservers go back
   to the live ones.

Rules: one open request per domain (a newer one supersedes the older); resubmitting the
same nameservers does nothing; changing back to the live ones withdraws the open request.
A DNS mismatch only means "keep waiting" and never marks a request failed. Requests an
admin makes are queued but not announced to admins.

## Settings (addon Configure page)

| Setting | Default | Notes |
|---------|---------|-------|
| Email admins | on | Uses `SendAdminEmail` type `system`: reaches staff roles that receive system emails |
| Open a support ticket / department ID / priority | off | Internal ticket, not linked to the customer |
| Telegram alerts / bot token / chat ID | off | Token from @BotFather |
| Slack alerts / webhook URL | off | Must start with `https://hooks.slack.com/` |
| Email customer when applied / failed | on | Failed email includes your reason |
| Auto-verify via DNS | on | Needs working DNS resolution from the server |
| Reminder after (hours) | 24 | Daily digest of requests pending longer |
| Customer change limit per domain per hour | 5 | 0 = unlimited; admins and cron are exempt |

## Known limits

- Nothing is sent to providers; the real change is always manual (or done by you elsewhere).
- Auto-verify trusts the server's DNS resolver, so a stale resolver cache delays it.
- A ticket opened for a request is not auto-closed when the request is applied.
- Child nameservers (`ns1.yourdomain.com` on `yourdomain.com`) are allowed but flagged
  "glue": create the child nameserver at the provider first.
- English only.

## Development

Dev-only tests run the classes against SQLite outside WHMCS (stubbing WHMCS functions):

```
cd tests && composer install
for t in smoke registrar admin notifier phase5; do php ${t}_test.php; done
```

They do not replace testing inside WHMCS. See [PLAN.md](PLAN.md) for the design.
