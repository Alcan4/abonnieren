<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/** Bounded, exponential retry delays in seconds. */
final class MailRetryPolicy {
	// With the capped backoff this keeps mail retrying for roughly two days.
	public const MAX_ATTEMPTS = 48;
	public const LEASE_SECONDS = 1800;
	public const SWEEP_INTERVAL_SECONDS = 300;
	public const HISTORY_SECONDS = 1209600; // 14 days.

	public static function delayForAttempt(int $attempts): int {
		return min(3600, 60 * (2 ** max(0, min($attempts - 1, 6))));
	}

	public static function exhausted(int $attempts): bool {
		return $attempts >= self::MAX_ATTEMPTS;
	}
}
