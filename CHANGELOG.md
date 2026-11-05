# Changelog

## Unreleased

## 1.1.12 - 2026-11-02

### Added

- Independent email and native Nextcloud notification channels for every
  subscription.
- Per-rule suppression windows, file-extension exclusions and relative folder
  exclusions with descendant overrides.
- Quiet hours, hourly and daily email digests, temporary subscriptions and
  per-rule snoozing.
- Reliable background mail delivery with persistent outbox state, bounded
  retries, abandoned-claim recovery and automatic removal of stored message
  content after completion.
- Ninety-second upload grouping with a sliding deadline, a ten-minute maximum
  window and a fifty-item limit.

### Changed

- Recheck the recipient's current access and subscription policy immediately
  before delayed mail or digest delivery.
- Resolve paths through the recipient's own Files view for email, Activity and
  native notifications.
- Run upgrade and integration coverage on Nextcloud 35 with SQLite, MariaDB and
  PostgreSQL, including upgrades from app version 1.1.2.

### Fixed

- Keep subscription replacement atomic so a failed save cannot remove the
  previous rule.
- Include the database rule ID in queued-message authorization, preventing an
  old deletion message from becoming valid after a rapid unsubscribe and
  resubscribe.
- Isolate delivery failures per recipient so one broken address cannot block
  later subscribers.
- Handle ownerless shares without raising `NoUserException`.
- Treat only explicit external download routes as file downloads. Internal,
  CLI, cron, preview and Office/WOPI reads no longer generate download email,
  Activity or native notifications. Authenticated WebDAV/Files downloads,
  public-share downloads and folder ZIP downloads remain covered.
- Generate absolute recipient-safe links for native Nextcloud notifications so
  Nextcloud 35 accepts and persists them.

### Security

- Remove public-share capability tokens and owner-private paths from queued
  messages and notification links.
- Cancel and scrub queued content when access is revoked or a subscription no
  longer authorizes delivery.

## 1.1.2 - 2026-09-22

### Changed

- Write an Activity entry (bell icon) for every subscription email — opens,
  downloads, uploads, modifications and deletions, including public-link
  events — to each subscriber who was emailed.

## 1.1.1 - 2026-09-22

### Fixed

- Fix invalid public-share URL regexes that aborted share resolution with
  `preg_match(): Unknown modifier ']'` and prevented notification emails.
- Notify on any real filesystem open/write (Files, shares, Text, Collabora,
  ONLYOFFICE, desktop clients, …) instead of an app URL allowlist. Only probes,
  tiny previews and internal system paths are ignored.

### Changed

- Download subscriptions now notify when a file is opened or downloaded, for
  any client that actually reads the file body.

## 1.1.0 - 2026-09-21

### Fixed

- Send modification emails when a file is edited through a public share link
  (including Nextcloud Text on `/s/…`). File changes stay in the native Files
  activity feed so they are not listed twice.
- Resolve public, email and internal shares the same way for download, upload,
  modification and deletion, including public DAV paths that are not mounted as
  shared storage.
- Translate notification emails into each subscriber's profile language instead
  of the language of the request that triggered the event. Fall back from
  regional codes such as de_DE to de so internal-share mails do not switch to
  the actor's language.

### Changed

- Debounce all notification categories (download, upload, modification,
  deletion) for 10 minutes per actor and file.

## 1.0.6 - 2026-09-21

### Changed

- Fill App Store metadata in `info.xml`: SPDX licence, bugs URL and repository.

## 1.0.5 - 2026-09-17

### Added

- Record a Files activity entry on the downloaded file or folder when another
  user downloads it. The file owner, the sharer and members of the admin
  group receive the entry. Public link downloads stay with the existing
  sharing activity so they are not listed twice.

## 1.0.4 - 2026-09-16

### Added

- OCS share-notification endpoints accept user and group shares in addition to
  public link shares, so internal shares can enable the same object rules.

### Fixed

- Send a single download email when a shared file is actually downloaded.
  Opening the file in the Viewer, previews and media playback are ignored.
  The Files menu still probes with DAV HEAD before GET; that probe is not a
  download either.

## 1.0.3 - 2026-08-17

### Changed

- First migration creates only `abonnieren_object_rules` and no longer imports
  rules from VB App.
- Require PHP 8.2, matching Nextcloud 34.
- Renamed the phpunit suite and removed unused constructor and helper code.

## 1.0.2 - 2026-08-12

### Fixed

- Load the Files initialization script without depending on internal Files
  classes that are unavailable during some Nextcloud 34 bootstrap sequences.
- Register the subscription tab in every active `@nextcloud/files` v4 scope so
  it is visible when the app bundle and the server use different minor scopes.

## 1.0.1 - 2026-08-12

### Fixed

- Allowed the read-only app start page to load without a CSRF failure.
- Registered the Files sidebar tab with the string display name required by
  `@nextcloud/files` 4.0.

## 1.0.0 - 2026-08-12

### Added

- Standalone file and folder subscription app for Nextcloud 34
- Files sidebar integration and subscription overview
- Download, upload, modification and deletion notifications
- Object-wide OCS integration for NextcloudShare
