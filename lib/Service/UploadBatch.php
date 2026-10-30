<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/**
 * Fixed 90-second upload windows. Grouping never mixes recipients,
 * actors, parent folders, or distinct time windows.
 */
final class UploadBatch {
	public const WINDOW_SECONDS = 90;
	public const MAX_ITEMS_PER_EMAIL = 50;

	/** @return array{key:string,due:int} */
	public static function scope(string $recipient, ?string $actor, int $parentId, int $timestamp): array {
		if ($recipient === '' || $parentId < 1) {
			throw new \InvalidArgumentException('Missing upload batch recipient or folder');
		}
		$bucket = intdiv($timestamp, self::WINDOW_SECONDS);
		$key = hash('sha256', json_encode([$recipient, $actor ?? '', $parentId, $bucket], JSON_THROW_ON_ERROR));
		return ['key' => $key, 'due' => ($bucket + 1) * self::WINDOW_SECONDS];
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows checked for current recipient access.
	 * @return array{subject:string,text:string,html:string}
	 */
	public static function message(array $rows): array {
		if (count($rows) < 2) {
			throw new \InvalidArgumentException('At least two uploads required for summary');
		}
		$count = count($rows);
		$names = [];
		foreach ($rows as $row) {
			$name = trim(str_replace(["\r", "\n", "\0"], ' ', (string)($row['item_name'] ?? '')));
			$names[] = $name !== '' ? $name : '(unnamed file)';
		}
		$subject = sprintf('%d files uploaded to a subscribed folder', $count);
		$text = $subject . "\n\n" . implode("\n", array_map(static fn (string $name): string => '- ' . $name, $names));
		$html = '<p>' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><ul>';
		foreach ($names as $name) {
			$html .= '<li>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
		}
		$html .= '</ul>';
		return ['subject' => $subject, 'text' => $text, 'html' => $html];
	}
}
