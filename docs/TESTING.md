# Manual test checklist (staging WHMCS 9.6)

Run on a staging copy. Use a test client and a test domain whose registrar is **Manual Registrar**.

## Install
- [ ] Addon activates; tables `mod_nsmanager_requests`, `_domains`, `_log` exist
- [ ] Manual Registrar activates; domain registrar can be set to it
- [ ] Addon access control limits who sees the menu entry
- [ ] Notifications tab: *Send test notification* succeeds for each enabled channel
      (admin email arrives; ticket appears; Telegram/Slack message arrives)

## Customer flow
- [ ] Client area > domain > nameservers: form loads with the saved/live nameservers
- [ ] Save two valid nameservers: success message, **Update pending** banner appears
- [ ] Reload: form still shows the newly requested nameservers
- [ ] Save one nameserver / an IP / duplicates: validation error shown, nothing queued
- [ ] Save again with different values: old request shows as superseded in History
- [ ] Save the nameservers back to the live ones: pending request is withdrawn
- [ ] Save 6 times within an hour (limit 5): "Too many requests" message
- [ ] Another client cannot change this domain (try the URL with the domain id)
- [ ] Banner does not appear on other pages or for non-manual domains

## Admin flow
- [ ] Pending tab lists the request with customer, provider link, old/new NS, age
- [ ] Copy button copies the new nameservers
- [ ] **Mark applied** (also try with the CSRF token: all buttons work, no "invalid token")
      -> status Applied, customer email received, customer sees live nameservers
- [ ] **Failed** asks for a reason -> customer email contains it, banner shows it
- [ ] **Cancel** asks for confirmation
- [ ] Bulk apply with two rows ticked
- [ ] Press Enter in the queue form: nothing happens
- [ ] Domain page: save provider, login URL (try `javascript:alert(1)`: rejected), live NS
- [ ] Audit history lists created / applied / failed / notify_failed events
- [ ] Layout looks right in the 9.6 admin theme (tabs, buttons, labels)

## Notifications
- [ ] New client request alerts every enabled channel; admin-made requests do not
- [ ] Break a channel (wrong Telegram token): customer save still works,
      `notify_failed` appears in the domain history and Activity Log, token is not shown
- [ ] A nameserver under the domain itself (ns1.thedomain.com): "glue" label + warning

## Cron
- [ ] Backdate a pending request by 25h (`UPDATE mod_nsmanager_requests SET created_at = ...`),
      run `php crons/cron.php`: one digest arrives; not repeated until 24h later
- [ ] Change a test domain's real nameservers to match a pending request, run cron:
      request auto-closes as `system`, customer is emailed
- [ ] Mismatching DNS keeps the request pending (never failed)
- [ ] After deploying 0.2.0 files, open Addon Modules once: `last_checked_at` column exists

## Safety
- [ ] Deactivate the addon: data tables remain; customers see the generic unavailable message
- [ ] Domain name / company name containing `<script>`: rendered as text everywhere
