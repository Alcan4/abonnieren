<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/**
 * Sliding 90-second quiet period per subscriber, actor and parent folder.
 * A 10-minute deadline prevents a continuous upload stream from starving mail.
 */
final class UploadBatch {
	public const QUIET_SECONDS = 90;
	public const MAX_WAIT_SECONDS = 600;
	public const MAX_ITEMS_PER_EMAIL = 50;
	public const SCOPE_RETENTION_SECONDS = 86400;

	/** A stable *scope* key; each separate burst gets a fresh batch key. */
	public static function scopeKey(string $recipient, ?string $actor, int $parentId): string {
		if ($recipient === '' || $parentId < 1) {
			throw new \InvalidArgumentException('Missing upload batch recipient or folder');
		}
		return hash('sha256', json_encode([$recipient, $actor ?? '', $parentId], JSON_THROW_ON_ERROR));
	}

	public static function dueAt(int $startedAt, int $lastUploadAt): int {
		return min($lastUploadAt + self::QUIET_SECONDS, $startedAt + self::MAX_WAIT_SECONDS);
	}

	public static function canAppend(int $now, int $startedAt, int $lastUploadAt, int $count): bool {
		return $startedAt > 0
			&& $lastUploadAt >= $startedAt
			&& $now >= $lastUploadAt
			&& $now < self::dueAt($startedAt, $lastUploadAt)
			&& $count < self::MAX_ITEMS_PER_EMAIL;
	}

}
