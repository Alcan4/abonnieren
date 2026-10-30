# TestEnV: pre-production scenario exercises

**Scope:** TestEnV only, after its Nextcloud migration is complete. These instructions are *not* permission to access, change or restart the server. Do not use production documents or actual customer email recipients.

**Prerequisites:** App 1.1.12 from the validated PR stack, Nextcloud 35, known PHP/DB versions, a verified backup, two throwaway accounts (one actor, one subscriber), and an inbox controlled by the tester. The testing package itself is not deployed automatically.

## 1. Automated CI proofs (no TestEnV access required)

These checks run on disposable Nextcloud 35 instances in GitHub Actions:
- Six separate PHP processes append to the same upload batch. All six rows must persist under one batch and one sliding deadline.
- The Nextcloud background-job worker processes a *real queued job* with a nonexistent file and cancels it, leaving no email content stored.
- SMTP error (fake transport) leads to a pending row with future retry time; after the simulated delay the second send succeeds, and the row's body is cleared.
- A separate Mailpit service captures one **real SMTP message** sent by the queued Nextcloud worker, using a throwaway file, subscription, and recipient. The test also confirms the outbox body is erased after successful delivery.
- A change to current file access cancels queued messages; grouped delivery omits inaccessible items.
- The existing CI jobs also exercise app upgrades from 1.1.2 to 1.1.12 on SQLite, MariaDB and PostgreSQL.

The single-pass Nextcloud job worker is used in CI, not a continuously running server cron. The actual TestEnV cron schedule must still be checked manually.

## 2. TestEnV baseline and safety

From the actual **TestEnV Nextcloud installation root** with the correct OS user, collect:

```bash
php occ status
php occ config:system:get dbtype
php occ config:system:get backgroundjobs_mode
php occ app:list --enabled
```

Check that the background job setting is `cron` and the configured operating-system timer (crontab/systemd) actually invokes the Nextcloud cron entry point at its expected cadence. Do not change the mode, user or schedule on an unknown instance without inspecting the existing setup first.

Record the installed app version and time of the test. Confirm a restorable **TestEnV database + data + config + app code** backup before any app upgrade.

## 3. Concurrent uploads and 90-second grouping

- Subscriber enables upload emails for an empty disposable folder; actor is a separate account.
- Actor uploads six small, synthetic files simultaneously (e.g. via the Files web UI or WebDAV with the actor's test-account credentials).
- Verify each file exists; verify that only one *email* arrives after 90 seconds of inactivity, not six. Cron delays may add extra time.
- Repeat with a new file after approximately 40 seconds and verify the send deadline slides by another 90 seconds.
- Repeat while two cron worker processes are scheduled, then verify no concurrent duplicate claim.
- Verify that Native Nextcloud notification delivery remains independent.
- Check the app's queued rows using **read-only** DB tooling where available. Compare only aggregate status counts, avoiding filenames and message bodies in shared logs.

## 4. SMTP outage, recovery and nonblocking uploads

Use the disposable mailbox and a controlled failure method approved for the **TestEnV** SMTP setup. Do not change production email settings.

- Temporarily make TestEnV's synthetic SMTP route unavailable, then upload a file.
- Confirm the upload itself succeeds, regardless of the mail transport failure.
- Verify a pending outbox row remains with attempt count increased, and a later `next_attempt_at` value. A stale job must not send before that time.
- Restore the SMTP route; check the next scheduled attempt delivers exactly one message.
- Confirm `sent` status and empty stored subject/body afterward; repeated job execution should not deliver the message twice.
- If SMTP accepts a message but the worker crashes before committing `sent`, a duplicate is possible; record this as an acknowledged at-least-once edge case.

## 5. Permission changes and queued deletion

- Subscriber can initially access a shared disposable file; a separate actor changes it while email processing is deliberately delayed (quiet hours or a scheduled digest).
- Revoke subscriber access before delivery. Verify no email containing inaccessible content is sent and the outbox message is cancelled and scrubbed.
- Restore access, subscribe to a folder and delete a synthetic file. Before delivery, unsubscribe from the folder or change its exclusion settings. Verify the deletion email is cancelled.
- Separately subscribe directly to a synthetic file, delete it, and verify an appropriate deletion message can still arrive while parent access remains valid.
- For multi-file digests, revoke one file's access before dispatch. The resulting summary must omit that file, not include its name or event.
- Recheck native notifications and the Activity stream separately; test for permission-related data exposure.

## 6. Safe diagnostics and sign-off

- Record Nextcloud/PHP versions, DB type, app 1.1.12 version, cron scheduler and synthetic test account IDs.
- Record times of test actions, received-message counts, and sanitized logs. **Do not commit** real addresses, login secrets, WebDAV tokens, share links, file contents, or raw outbox bodies to GitHub.
- Note whether each scenario is **PASS / FAIL / NOT RUN**, with the exact issue/PR for any failures.
- Do not merge stacked draft PRs or deploy to production until TestEnV tests pass.
- If any migration or permission check fails, stop and restore matching verified backups; avoid manual DB schema edits.

See [TESTENV-VALIDATION.md](TESTENV-VALIDATION.md) for the complete migration and rollback plan.
