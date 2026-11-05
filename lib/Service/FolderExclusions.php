<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

/**
 * Excludes specific directory paths relative to the subscribed folder.
 * No glob expansion or filesystem traversal: names are compared by path segments.
 */
final class FolderExclusions {
	public const MAX_LENGTH = 1000;
	public const MAX_PATHS = 30;

	/** @return list<string> */
	public static function parse(string $input): array {
		if (strlen($input) > self::MAX_LENGTH) {
			throw new \InvalidArgumentException('Folder exclusion list is too long');
		}
		$paths = [];
		foreach (explode(',', $input) as $part) {
			$path = trim($part);
			if ($path === '') {
				continue;
			}
			if ($path[0] === '/' || str_contains($path, '\\')
				|| preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
				throw new \InvalidArgumentException('Use relative folder paths, without leading slash or backslash');
			}
			$segments = explode('/', $path);
			foreach ($segments as $segment) {
				if ($segment === '' || $segment === '.' || $segment === '..' || trim($segment) !== $segment) {
					throw new \InvalidArgumentException('Invalid folder path segment');
				}
			}
			$paths[$path] = $path;
		}
		if (count($paths) > self::MAX_PATHS) {
			throw new \InvalidArgumentException('Too many excluded folders');
		}
		return array_values($paths);
	}

	public static function normalize(string $input): string {
		return implode(',', self::parse($input));
	}

	/**
	 * @param list<string> $excluded
	 * @param list<string> $relativeSegments Directory/file names below the subscription node.
	 */
	public static function matches(array $excluded, array $relativeSegments, bool $isFile = false): bool {
		if ($isFile) {
			array_pop($relativeSegments); // The final segment is a filename, not a directory.
		}
		if ($relativeSegments === []) {
			return false;
		}
		foreach ($excluded as $path) {
			$prefix = explode('/', $path);
			if (count($prefix) > count($relativeSegments)) {
				continue;
			}
			if (array_slice($relativeSegments, 0, count($prefix)) === $prefix) {
				return true;
			}
		}
		return false;
	}
}
