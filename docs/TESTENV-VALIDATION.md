# TestEnV validation plan — Abonnieren 1.1.11 development stack

**Status:** Pre-deployment checklist only. All feature PRs and this integration PR remain drafts. **Never apply this to production.**

## Automated gates

The latest GitHub Actions run must pass:
- PHP syntax and app metadata
- Nextcloud 35 PHPUnit, including *real* SQLite queries, migrations, service injection, atomic mail claims, SMTP retry and upload grouping
- Frontend lint/build and the packaged **abonnieren-TestEnV** ZIP
- A disposable **1.1.2 → 1.1.11 upgrade**, preserving an existing subscription and its new default settings

A green CI run does not replace real client testing.

## Before touching TestEnV

1. Finish migration; record the Nextcloud version and confirm it using php occ status. This app targets Nextcloud 34–35, with staging tests on Nextcloud 35.
2. Identify the database backend, PHP version and cron configuration. Prepare two disposable test users and a test folder without confidential files.
3. Create a **timestamped, restorable backup** of the TestEnV database, config, data and existing apps/abonnieren app code. Verify restoration is possible. The updater's code backup does not necessarily include the database or data.
4. Obtain the ZIP from the successful Actions run of PR #17. Inspect the archive; it must contain one abonnieren/ directory with the compiled js/ and css/ directories.
5. Stop test clients from producing new events during the upgrade; record the existing number of subscription rules.

## Upgrade verification (only on TestEnV)

1. Run php occ maintenance:mode --on from the Nextcloud installation directory.
2. Replace only the TestEnV app directory with the validated ZIP contents; preserve file permissions and ownership.
3. Run php occ upgrade --no-interaction and check the exit code and logs. The expected app version is 1.1.11.
4. Run php occ maintenance:mode --off, check php occ status, inspect Files, and verify subscriptions and permissions survived.
5. Verify Nextcloud cron is actually running; execute the configured cron runner when needed (typically php -f cron.php).

**If an upgrade fails, stop.** Never fix it by manually changing app-version keys or dropping/recreating live tables. Use the matching backup and investigate first.

## Real-world behavior checklist

- [ ] Upload, modify, download and delete: expected email and Activity entries; no unwanted self-notification.
- [ ] Email only, Nextcloud notification only and both, including different options for different subscribers.
- [ ] Suppression off, 60 seconds and 600 seconds; repeated events are suppressed only for the intended recipient and channel.
- [ ] Extension exclusions and nested folder exclusions (tmp, Archive, Projects/Drafts), including similarly named directories and explicit descendant subscription overrides.
- [ ] Multiple uploads fewer than 90 seconds apart generate one summary **90 seconds after the last upload**; batches cap at 10 minutes / 50 items.
- [ ] Previously scheduled jobs must not send before the *current* batch deadline.
- [ ] Hourly/daily digests and overnight quiet hours; verify delivery resumes afterward.
- [ ] Temporary subscriptions expire, snoozed subscriptions resume, and nested overrides work correctly.
- [ ] Revoke access before queued mail delivery; inaccessible file details must not be sent.
- [ ] Simulate a temporary SMTP failure without interrupting the uploader; verify bounded retries and eventual recovery.
- [ ] Restart the cron worker after enqueue to check that persistent mail is recoverable.
- [ ] Parallel uploads and concurrent workers: no simultaneous duplicate claim. SMTP cannot guarantee exactly-once delivery after a crash during remote acceptance.

## Diagnostics and sign-off

Inspect Nextcloud application logs and outbox status counts (pending, sending, sent, failed, cancelled). Capture sanitized timestamps and error descriptions in PR #17, **never confidential filenames, email addresses or tokens**. Note Nextcloud/PHP/database versions, cron mode, test observations and resulting action items.

Keep all PRs in draft until migration, access permissions, delivery and retries have passed staging. Merge stacked changes in dependency order only after review.

## Rollback

To roll back a schema-changing upgrade: enter maintenance mode, restore the **matching database, app directory, config and data backups together**, and verify the Nextcloud instance before restarting cron and clients. Replacing only app code is not a reliable rollback after database migrations.
