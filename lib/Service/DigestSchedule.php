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

	public static function key(string $recipient, int $due): string {
		if ($recipient === '' || $due < 1) {
			throw new \InvalidArgumentException('Invalid digest recipient or deadline');
		}
		return hash('sha256', json_encode([$recipient, $due], JSON_THROW_ON_ERROR));
	}

	/**
	 * @param list<array<string,mixed>> $rows Already checked for current access.
	 * @return array{subject:string,text:string,html:string}
	 */
	public static function message(array $rows): array {
		if (count($rows) < 2) {
			throw new \InvalidArgumentException('At least two events required for digest');
		}
		$subject = sprintf('%d subscription events', count($rows));
		$text = $subject;
		$html = '<p>' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><ul>';
		foreach ($rows as $row) {
			$action = trim(str_replace(["\r", "\n", "\0"], ' ', (string)($row['subject'] ?? 'File activity')));
			$name = trim(str_replace(["\r", "\n", "\0"], ' ', (string)($row['item_name'] ?? '')));
			$item = $name === '' ? $action : $action . ': ' . $name;
			$text .= "\n- " . $item;
			$html .= '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
		}
		return ['subject' => $subject, 'text' => $text, 'html' => $html . '</ul>'];
	}
}
