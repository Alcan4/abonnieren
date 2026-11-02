<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Resolve paths exclusively inside the recipient's accessible user folder.
 * The original owner path can contain private ancestor names that are not
 * part of the recipient's shared mount.
 */
class RecipientVisiblePath {
	public function __construct(private IRootFolder $rootFolder) {
	}

	public function pathFor(string $userId, Node $node, bool $deleted = false): ?string {
		if ($userId === '' || (int)$node->getId() < 1) {
			return null;
		}
		try {
			$target = $deleted ? $node->getParent() : $node;
			$matches = $this->rootFolder->getUserFolder($userId)->getById((int)$target->getId());
			if ($matches === []) {
				return null;
			}
			$parts = explode('/', trim($matches[0]->getPath(), '/'));
			if (count($parts) < 2 || $parts[1] !== 'files') {
				return null;
			}
			$visible = '/' . implode('/', array_slice($parts, 2));
			if (!$deleted) {
				return $visible;
			}
			// The deleted object itself is no longer resolvable. Only expose
			// its original name below a still-accessible recipient parent.
			$name = $node->getName();
			if ($name === '' || str_contains($name, '/')) {
				return null;
			}
			return rtrim($visible, '/') . '/' . $name;
		} catch (\Throwable $e) {
			return null;
		}
	}
}
