<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\Files\File;
use OCP\Files\Node;

/** Per-subscription throttling and file-extension exclusions. */
final class SubscriptionPreferences {
	public const DEFAULT_DEBOUNCE_SECONDS = 600;
	public const MAX_DEBOUNCE_SECONDS = 3600;

	/** @return list<string> */
	public static function parseExtensions(string $value): array {
		if (strlen($value) > 500) {
			throw new \InvalidArgumentException('Extension exclusion list is too long');
		}
		$result = [];
		foreach (explode(',', $value) as $part) {
			$extension = strtolower(trim($part));
			if ($extension === '') {
				continue;
			}
			$extension = preg_replace('/^\*?\./', '', $extension);
			if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,15}(?:\.[a-z0-9][a-z0-9_-]{0,15})?$/D', $extension)) {
				throw new \InvalidArgumentException('Invalid excluded file extension');
			}
			$result[$extension] = $extension;
		}
		if (count($result) > 30) {
			throw new \InvalidArgumentException('No more than 30 excluded extensions are allowed');
		}
		return array_values($result);
	}

	public static function normalizeExtensions(string $input): string {
		return implode(',', self::parseExtensions($input));
	}

	public static function validateDebounce(int $seconds): int {
		if ($seconds < 0 || $seconds > self::MAX_DEBOUNCE_SECONDS) {
			throw new \InvalidArgumentException('Suppression window must be between 0 and 3600 seconds');
		}
		return $seconds;
	}

	/** @param list<string> $extensions */
	public static function isExcluded(Node $node, array $extensions): bool {
		if (!$node instanceof File) {
			return false;
		}
		$name = strtolower($node->getName());
		foreach ($extensions as $extension) {
			if (str_ends_with($name, '.' . $extension)) {
				return true;
			}
		}
		return false;
	}
}
