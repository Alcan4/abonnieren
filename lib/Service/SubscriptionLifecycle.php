<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/** Temporary subscriptions and per-rule notification snoozing. Unix timestamps are in UTC. */
final class SubscriptionLifecycle {
	public const MAX_EXPIRATION_SECONDS = 366 * 86400;
	public const MAX_SNOOZE_SECONDS = 90 * 86400;

	/** @return array{expiresAt:int,snoozedUntil:int} */
	public static function validate(int $expiresAt, int $snoozedUntil, int $now): array {
		if ($expiresAt < 0 || $snoozedUntil < 0) {
			throw new \InvalidArgumentException('Dates must be non-negative Unix timestamps');
		}
		if ($expiresAt !== 0 && ($expiresAt <= $now || $expiresAt > $now + self::MAX_EXPIRATION_SECONDS)) {
			throw new \InvalidArgumentException('Expiration must be in the next 366 days');
		}
		if ($snoozedUntil > $now + self::MAX_SNOOZE_SECONDS) {
			throw new \InvalidArgumentException('Snooze must be within the next 90 days');
		}
		return ['expiresAt' => $expiresAt, 'snoozedUntil' => max(0, $snoozedUntil <= $now ? 0 : $snoozedUntil)];
	}

	public static function expired(int $expiresAt, int $now): bool {
		return $expiresAt > 0 && $expiresAt <= $now;
	}

	public static function snoozed(int $until, int $now): bool {
		return $until > $now;
	}
}
