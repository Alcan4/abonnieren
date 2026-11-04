<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IRequest;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/** Resolves the share and owner node for subscription listeners. */
class ShareContextResolver {
	public function __construct(
		private IRequest $request,
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function getShare(Node $node): ?IShare {
		try {
			$storage = $node->getStorage();
			if ($storage->instanceOfStorage(ISharedStorage::class)) {
				/** @var ISharedStorage $storage */
				return $storage->getShare();
			}
		} catch (NotFoundException $e) {
			// Continue with request-token lookup below.
		}

		foreach ($this->getShareTokensFromRequest() as $token) {
			try {
				$share = $this->shareManager->getShareByToken($token);
				if ($this->shareCoversNode($share, $node)) {
					return $share;
				}
			} catch (ShareNotFound $e) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Resolve the share for messaging. When the request has no logged-in user,
	 * also look up a public/email share covering the node so wording stays accurate.
	 */
	public function resolveShareContext(Node $node, bool $allowPublicLookup, int $requiredPermissions = 0): ?IShare {
		$share = $this->getShare($node);
		if ($share !== null || !$allowPublicLookup) {
			return $share;
		}

		return $this->findPublicShareForNode($node, $requiredPermissions);
	}

	public function isPublicShare(?IShare $share): bool {
		return $share !== null && in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true);
	}

	public function findPublicShareForNode(Node $node, int $requiredPermissions = 0): ?IShare {
		$ownerId = $node->getOwner()?->getUID();
		if (!is_string($ownerId) || $ownerId === '') {
			return null;
		}

		$current = $node;
		$depth = 0;
		while ($current !== null && $depth < 101) {
			foreach ([IShare::TYPE_LINK, IShare::TYPE_EMAIL] as $shareType) {
				try {
					$shares = $this->shareManager->getSharesBy($ownerId, $shareType, $current, true, -1);
				} catch (\Throwable $e) {
					continue;
				}
				foreach ($shares as $share) {
					if ($requiredPermissions === 0 || ($share->getPermissions() & $requiredPermissions) !== 0) {
						return $share;
					}
				}
			}

			try {
				$current = $current->getParent();
			} catch (\Throwable $e) {
				return null;
			}
			$depth++;
		}

		return null;
	}

	public function resolveOwnerNode(Node $node, ?IShare $share): Node {
		if ($share === null) {
			return $node;
		}

		try {
			$shareNode = $share->getNode();
			if ($shareNode->getId() === $node->getId()) {
				return $shareNode;
			}

			$sharedBy = $share->getSharedBy();
			if ($sharedBy === '') {
				return $node;
			}

			$ownerFolder = $this->rootFolder->getUserFolder($sharedBy);
			$matches = $ownerFolder->getById($node->getId());
			if ($matches !== []) {
				return $matches[0];
			}

			return $shareNode;
		} catch (\Throwable $e) {
			$this->logger->debug('Could not resolve owner node for subscription', [
				'app' => 'abonnieren',
				'nodeId' => (int)$node->getId(),
				'exception' => $e,
			]);
			return $node;
		}
	}

	/** @return list<string> */
	private function getShareTokensFromRequest(): array {
		$tokens = [];
		foreach (['token', 'shareToken', 'sharingToken'] as $key) {
			$value = $this->request->getParam($key, '');
			if (is_string($value) && $value !== '') {
				$tokens[] = $value;
			}
		}

		$uri = $this->request->getRequestUri();
		if (preg_match('~/(?:s|public\.php/dav/files|remote\.php/dav/public-files)/([^/?#]+)~', $uri, $matches) === 1) {
			$tokens[] = rawurldecode($matches[1]);
		}

		return array_values(array_unique($tokens));
	}

	private function shareCoversNode(IShare $share, Node $node): bool {
		$shareNodeId = (int)$share->getNodeId();
		if ($shareNodeId < 1) {
			return false;
		}

		$current = $node;
		$depth = 0;
		while ($current !== null && $depth < 101) {
			if ((int)$current->getId() === $shareNodeId) {
				return true;
			}
			try {
				$current = $current->getParent();
			} catch (\Throwable $e) {
				return false;
			}
			$depth++;
		}

		return false;
	}
}
