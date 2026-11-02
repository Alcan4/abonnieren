<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/** Computes the end of a local-time quiet period, honoring DST and overnight ranges. */
final class EmailSchedule {
	/** @return array{start:string,end:string,timezone:string} */
	public static function validate(string $start, string $end, string $timezone): array {
		$start = trim($start);
		$end = trim($end);
		if (($start === '') !== ($end === '')) {
			throw new \InvalidArgumentException('Set both quiet hours or leave both empty');
		}
		if ($start !== '' && (!self::validClock($start) || !self::validClock($end) || $start === $end)) {
			throw new \InvalidArgumentException('Invalid quiet-hours time range');
		}
		if ($timezone === '' || strlen($timezone) > 64) {
			throw new \InvalidArgumentException('Invalid quiet-hours timezone');
		}
		try {
			new \DateTimeZone($timezone);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException('Unknown IANA timezone', 0, $e);
		}
		return ['start' => $start, 'end' => $end, 'timezone' => $timezone];
	}

	public static function dueAt(int $unixTime, string $start, string $end, string $timezone): int {
		$settings = self::validate($start, $end, $timezone);
		if ($settings['start'] === '') {
			return $unixTime;
		}
		$local = (new \DateTimeImmutable('@' . $unixTime))
			->setTimezone(new \DateTimeZone($timezone));
		$startMinutes = self::minutes($start);
		$endMinutes = self::minutes($end);
		$nowMinutes = (int)$local->format('G') * 60 + (int)$local->format('i');

		if ($startMinutes < $endMinutes) {
			$quiet = $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
			$endDate = $local;
		} else {
			$quiet = $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
			$endDate = $nowMinutes >= $startMinutes ? $local->modify('+1 day') : $local;
		}
		if (!$quiet) {
			return $unixTime;
		}

		$until = $endDate->setTime(intdiv($endMinutes, 60), $endMinutes % 60);
		// An ambiguous/invalid local time on a DST boundary is resolved by PHP's timezone rules.
		return max($unixTime, $until->getTimestamp());
	}

	private static function validClock(string $clock): bool {
		return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock) === 1;
	}

	private static function minutes(string $clock): int {
		return ((int)substr($clock, 0, 2)) * 60 + (int)substr($clock, 3, 2);
	}
}
