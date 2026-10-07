# WHMCS Manual Registrar + Nameserver Manager

Let customers update the nameservers of domains you bought at **external providers**, even
when those domains are not connected to any WHMCS registrar API.

A customer's change is saved as a *pending request*. You see it in an admin dashboard (and in
email, Telegram, Slack or a ticket), apply it at the provider, and mark it applied. Or let the
cron job notice that the live DNS already matches and close it for you.

> **Status:** built and tested outside WHMCS (SQLite + stubs). Run the
> [staging checklist](docs/TESTING.md) on a WHMCS 9.6 copy before using it in production.

---

## Features

**For customers**
- Standard WHMCS nameserver form (up to 5 nameservers) with validation (hostnames only, no IPs, no duplicates).
- "Update pending" notice on the domain page while a change is being processed.
- Email when the change is applied, or when it could not be done (with the reason).
- Always sees their requested nameservers while pending, and the live ones afterwards.

**For you (admin dashboard)**
- Queue tabs: **Pending** (with a counter), **Applied**, **Failed**, **All**, **Providers**, **Notifications**.
- Each request shows domain, customer, provider (with a login link), old and new nameservers,
  who asked and from which IP, and how long it has been waiting (red after 24h).
- One-click **Mark applied**, **Failed** (reason required), **Cancel**, plus **bulk apply**.
- **Copy** button for the new nameservers, so you can paste them into the provider panel.
- Per-domain page: provider, login URL, account label, notes, the nameservers currently live at
  the provider, all requests, and a full audit history.
- Warning label when a nameserver sits under the domain itself (needs a glue record at the provider).

**Notifications**
- Admin alerts by **email**, **internal support ticket**, **Telegram** and **Slack**.
- **Daily reminder digest** for requests pending longer than a configurable time (default 24h).
- Failed channels never block the customer; failures are logged in the audit history.

**Automation and safety**
- **DNS auto-verify:** each cron run compares the domain's live nameservers with pending requests and closes matches.
- One open request per domain; a newer one supersedes the older; resubmitting the same values does nothing.
- Per-domain hourly rate limit for customers (default 5).
- Concurrent saves are serialised with row locks.
- All output is escaped; provider links accept only `http(s)`; secrets are scrubbed from logs.

---

## Requirements

| | |
|---|---|
| WHMCS | 9.6 |
| PHP | 8.2 (with `curl`, `mbstring`, `intl` recommended for internationalised domains) |
| Database | MySQL / MariaDB as required by WHMCS (tables are created automatically) |
| Cron | WHMCS system cron running every 5 minutes |
| Outbound HTTPS | Only if you use Telegram (`api.telegram.org`) or Slack (`hooks.slack.com`) |

---

## Installation

### 1. Copy the files

Upload the contents of this repository into your WHMCS root, keeping the folder structure:

```
<whmcs-root>/
├── includes/hooks/nsmanager.php
└── modules/
    ├── addons/nsmanager/        (admin dashboard, notifications, shared code)
    └── registrars/manualreg/    (customer-facing registrar module)
```

Example over SSH (adjust paths):

```bash
cd /tmp && git clone https://github.com/arifbillahcse/WHMCS-Billing-Software-Domain_registratio-addon.git nsm
rsync -av nsm/includes nsm/modules /var/www/whmcs/
chown -R <web-user>:<web-user> /var/www/whmcs/includes/hooks/nsmanager.php \
  /var/www/whmcs/modules/addons/nsmanager /var/www/whmcs/modules/registrars/manualreg
```

Do not upload the `tests/`, `docs/` folders or the `.md` files; they are not needed on the server.

### 2. Activate the addon first

1. Admin area -> **System Settings -> Addon Modules**.
2. Find **Nameserver Manager** -> **Activate**. This creates the tables
   `mod_nsmanager_requests`, `mod_nsmanager_domains` and `mod_nsmanager_log`.
3. Click **Configure**, set **Access Control** to the staff roles that may use it, and save.

### 3. Activate the registrar

1. **System Settings -> Domain Registrars** -> **Activate** *Manual Registrar* (no credentials needed).

> Activate the addon **before** switching any domain to Manual Registrar. Without the addon's
> tables customers see a generic "temporarily unavailable" message and the real error is written
> to *Utilities -> Logs -> Module Log*.

### 4. Point your external domains at it

For each domain bought elsewhere: open it in the admin area, set **Registrar** to
**Manual Registrar**, and save. Domains must be Active for customers to manage nameservers.

### 5. Record the current setup

Open **Addons -> Nameserver Manager -> Providers**, click **Edit** on a domain and fill in:

- **Provider** (Namecheap, GoDaddy, ...) and **login URL**
- **Account label** (which of your logins holds the domain)
- **Nameservers live at the provider** (so "old" values are accurate)

### 6. Configure notifications

**System Settings -> Addon Modules -> Nameserver Manager -> Configure**, then open
**Addons -> Nameserver Manager -> Notifications** and press **Send test notification**.

| Setting | Default | Notes |
|---|---|---|
| Email admins | on | Sent as a WHMCS *system* email: reaches staff roles that receive system emails |
| Open a support ticket | off | Needs *Ticket department ID*; the ticket is internal (not linked to the customer) |
| Telegram alerts | off | Create a bot with @BotFather; set *bot token* and *chat ID* |
| Slack alerts | off | Incoming webhook; URL must start with `https://hooks.slack.com/` |
| Email customer when applied / failed | on | The failure reason you enter is included |
| Auto-verify via DNS | on | Needs working DNS resolution on the server |
| Reminder after (hours) | 24 | Daily digest of requests pending longer than this |
| Customer change limit per domain per hour | 5 | 0 = unlimited; admins and cron are exempt |

### 7. Make sure cron is running

Reminders and DNS auto-verify depend on the WHMCS cron, normally:

```bash
*/5 * * * * php -q /var/www/whmcs/crons/cron.php
```

---

## Daily use

1. A customer changes nameservers in the client area -> you get an alert.
2. Open **Addons -> Nameserver Manager -> Pending**, copy the new nameservers, log in at the
   provider (link in the Provider column) and apply them.
3. Click **Mark applied**. The customer is emailed and sees the live nameservers.
   If you skip this step, the next cron run closes the request automatically once DNS matches.
4. If you can't do it, click **Failed** and give a reason. The customer gets it by email and sees
   it on their domain page for 7 days; their displayed nameservers go back to the live ones.

Rules worth knowing:
- Requests created by an admin (for example from the admin domain page) are queued but not announced to admins.
- A DNS mismatch only means "keep waiting"; auto-verify never marks a request failed.
- Pending requests for deleted domains, or domains moved to another registrar, are cancelled by the daily cron.

---

## Upgrading

1. Copy the new files over the old ones.
2. Open **System Settings -> Addon Modules** once. WHMCS runs the addon's upgrade, which adds new
   columns (0.2.0 adds `last_checked_at`). Until then the cron hooks stay idle on purpose.

Data is never deleted when you deactivate the addon.

## Uninstalling

1. Switch the affected domains to another registrar (or leave them; they will just stop managing nameservers).
2. Deactivate **Manual Registrar** and **Nameserver Manager**.
3. Delete the three folders/files from step 1.
4. Optional, to remove all data: `DROP TABLE mod_nsmanager_requests, mod_nsmanager_domains, mod_nsmanager_log;`

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Red "An issue was encountered while retrieving / updating the domain nameservers" on the client's Nameservers tab | WHMCS shows its own generic text and hides the module's message. Read the real reason in **Utilities -> Logs -> Activity Log** (line starting `Manual Registrar ...`); see the table below |
| Customer sees "temporarily unavailable" | Same as above: the addon is not activated or its files are incomplete. Details are in the *Activity Log* and *Module Log* (module `manualreg`) |
| No admin emails | The staff role must receive *system* emails; try the Notifications test button |
| Telegram/Slack test fails | Token/webhook correct? Server can reach the host? See *Activity Log* |
| "Update pending" notice never appears | Needs the `ClientAreaFooterOutput` hook and the `clientareadomaindetails` template; custom themes may differ |
| Requests never auto-close | Cron running? Auto-verify enabled? The server's resolver may hold stale DNS |
| Dashboard buttons do nothing / "invalid token" | Check the addon is opened through `addonmodules.php?module=nsmanager` |

**What the Activity Log line means**

| Log message contains | Cause and fix |
|---|---|
| `Nameserver Manager addon is not activated` | The addon tables do not exist. Activate **Nameserver Manager** under System Settings -> Addon Modules (install step 2), then reload the client page |
| `Nameserver Manager addon files not found` | `modules/addons/nsmanager/` was not uploaded completely (the `lib/` folder is required by the registrar). Re-upload it |
| `Base table or view not found` / `Unknown column` | Activation did not finish, or you upgraded without opening Addon Modules. Open System Settings -> Addon Modules once; if it persists, deactivate and activate the addon (data is kept) |
| Anything else | Copy the whole line (it includes the file and line number) and report it |

Also check that the domain's **Registrar** is **Manual Registrar** and that **Utilities -> Logs -> Module Log** is
enabled if you want the full trace of each call.

---

## Known limits

- Nothing is sent to providers: the real change is always done by you (or by a provider API you add later).
- A ticket opened for a request is not closed automatically when it is applied.
- A domain registered through a WHMCS order starts with unknown live nameservers; enter them once on the domain page.
- Admin dashboard text is English only.

---

## Repository layout

```
modules/addons/nsmanager/     addon: dashboard, services, notifications, cron logic
  lib/                        Schema, Repository, RequestService, Validator, Notifier, Cron, ...
  templates/                  admin pages
modules/registrars/manualreg/ registrar module
includes/hooks/nsmanager.php  loads the addon's cron and client-area hooks
docs/TESTING.md               manual checklist for a staging WHMCS
tests/                        dev-only tests (SQLite + stubs)
PLAN.md                       design, phases and review log
```

## Development

The tests run the classes against SQLite outside WHMCS:

```bash
cd tests && composer install
for t in smoke registrar admin notifier phase5 review isolation; do php ${t}_test.php; done
```

They do not replace testing inside WHMCS. See [PLAN.md](PLAN.md) for the design and review notes.
