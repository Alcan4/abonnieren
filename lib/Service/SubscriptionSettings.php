<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/** Named subscription options passed from controllers to persistence. */
final class SubscriptionSettings {
	public function __construct(
		public readonly int $debounceSeconds = SubscriptionPreferences::DEFAULT_DEBOUNCE_SECONDS,
		public readonly string $excludedExtensions = '',
		public readonly int $channelMask = SubscriptionService::CHANNEL_EMAIL,
		public readonly string $quietStart = '',
		public readonly string $quietEnd = '',
		public readonly string $quietTimezone = 'UTC',
		public readonly string $digestMode = DigestSchedule::INSTANT,
		public readonly string $digestTime = '08:00',
		public readonly int $expiresAt = 0,
		public readonly int $snoozedUntil = 0,
		public readonly string $excludedFolders = '',
	) {
	}
}
