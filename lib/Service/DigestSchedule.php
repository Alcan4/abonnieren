<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/** Opt-in hourly/daily email summaries in the subscriber's saved timezone. */
final class DigestSchedule {
	public const INSTANT = 'instant';
	public const HOURLY = 'hourly';
	public const DAILY = 'daily';
	public const MAX_ITEMS_PER_EMAIL = 50;

	public static function validate(string $mode, string $time): void {
		if (!in_array($mode, [self::INSTANT, self::HOURLY, self::DAILY], true)) {
			throw new \InvalidArgumentException('Invalid email digest frequency');
		}
		if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
			throw new \InvalidArgumentException('Invalid daily digest time');
		}
	}

	public static function dueAt(
		int $now, string $mode, string $dailyTime, string $timezone,
		string $quietStart = '', string $quietEnd = '',
	): int {
		self::validate($mode, $dailyTime);
		EmailSchedule::validate($quietStart, $quietEnd, $timezone);
		if ($mode === self::INSTANT) {
			return EmailSchedule::dueAt($now, $quietStart, $quietEnd, $timezone);
		}
		$local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone));
		if ($mode === self::HOURLY) {
			$target = $local->setTime((int)$local->format('G'), 0)->modify('+1 hour');
		} else {
			[$hour, $minute] = array_map('intval', explode(':', $dailyTime));
			$target = $local->setTime($hour, $minute);
			if ($target->getTimestamp() <= $now) {
				$target = $target->modify('+1 day');
			}
		}
		return EmailSchedule::dueAt($target->getTimestamp(), $quietStart, $quietEnd, $timezone);
	}

	public static function key(string $recipient, int $due, string $quietStart = '', string $quietEnd = '', string $timezone = 'UTC'): string {
		if ($recipient === '' || $due < 1) {
			throw new \InvalidArgumentException('Invalid digest recipient or deadline');
		}
		return hash('sha256', json_encode([$recipient, $due, $quietStart, $quietEnd, $timezone], JSON_THROW_ON_ERROR));
	}

}
