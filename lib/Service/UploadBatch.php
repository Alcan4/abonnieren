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
