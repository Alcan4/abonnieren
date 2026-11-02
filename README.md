# Abonnieren

Abonnieren provides privacy-aware file and folder subscriptions for Nextcloud
34 and 35. Users can subscribe to individual items or folder trees and choose
notifications for uploads, modifications, deletions and real downloads.

## Demo

![Creating a Nextcloud share from Windows Explorer](abonnieren-demo.gif)

## Highlights

- Files sidebar tab **Subscribe**
- Overview **My subscriptions**
- Email, native Nextcloud notifications, or both per rule
- Optional recursive folder rules with specific descendant overrides
- Stable node IDs, so rules survive rename and move operations
- Suppression windows and extension/folder exclusions
- Quiet hours, hourly/daily digests, temporary rules and snoozing
- Ninety-second grouping for related uploads
- Suppression of the subscriber's own authenticated actions
- Deduplication of overlapping direct and recursive rules
- User, group and public-link uploads, modifications, deletions and real
  downloads
- Activity feed entries and recipient-safe notification links
- Complete folder ZIP download notifications
- OCS endpoint for trusted clients such as NextcloudShare

## Delivery and privacy

Email delivery uses a persistent background outbox with bounded retries, so a
temporary SMTP problem does not block the file request. The worker checks the
recipient's current access and subscription again before sending. Completed,
cancelled and terminal messages are scrubbed of their stored subject and body.

Paths are resolved through the recipient's own Files view. Public-share tokens
and owner-private paths are not persisted in queued messages. Internal CLI,
cron, preview and Office/WOPI reads are excluded from download alerts, while
authenticated WebDAV/Files downloads, public-share downloads and explicit
folder ZIP downloads remain supported.

Background jobs must be configured in **Cron** mode for timely grouping,
digests and retries.

Event mask: upload `1`, modification `2`, deletion `4`, download `8`.

## NextcloudShare API

```text
POST /ocs/v2.php/apps/abonnieren/api/v1/share-notifications
```

Parameters:

- `shareId`: numeric OCS share ID
- `eventMask`: value from `1` through `15`

Authentication uses the user's normal Nextcloud app password. The app verifies that the user, group or public link share belongs to that user, resolves its shared node and creates or replaces the corresponding object-wide rule. The share ID is not stored in the rule.

## Development

```bash
pnpm install --frozen-lockfile
pnpm build
composer install
composer lint
```

Release archives must contain a single top-level folder named `abonnieren`.

## License

AGPL-3.0-or-later
