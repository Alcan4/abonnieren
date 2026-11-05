<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Node;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;

/** Stores and resolves one notification rule per user and file/folder node. */
class SubscriptionService {
	public const EVENT_UPLOAD = 1;
	public const EVENT_MODIFICATION = 2;
	public const EVENT_DELETION = 4;
	public const EVENT_DOWNLOAD = 8;
	public const ALLOWED_EVENTS = 15;
	public const DEBOUNCE_SECONDS = 600;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}

	public function upsertRule(string $userId, int $nodeId, int $eventMask, bool $recursive): void {
		$this->validateRule($userId, $nodeId, $eventMask);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('abonnieren_object_rules')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)));
		$qb->executeStatement();

		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('abonnieren_object_rules')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'node_id' => $qb->createNamedParameter($nodeId),
				'event_mask' => $qb->createNamedParameter($eventMask),
				'recursive' => $qb->createNamedParameter($recursive ? 1 : 0),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
	}

	public function deleteRule(string $userId, int $nodeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('abonnieren_object_rules')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)));

		return $qb->executeStatement();
	}

	public function deleteRulesForNode(int $nodeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('abonnieren_object_rules')
			->where($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)));

		return $qb->executeStatement();
	}

	public function getRule(string $userId, int $nodeId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive', 'created_at', 'updated_at')
			->from('abonnieren_object_rules')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return is_array($row) ? $this->normalizeRow($row) : null;
	}

	public function getRulesByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive', 'created_at', 'updated_at')
			->from('abonnieren_object_rules')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('updated_at', 'DESC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
	}

	/** @return array<string, string> Recipient e-mail addresses keyed by user id. */
	public function getRecipientEmailsForNode(Node $node, int $event, ?string $actorUserId = null): array {
		$emails = [];
		foreach ($this->getRecipientsForNode($node, $event, $actorUserId) as $userId => $email) {
			if (is_string($email) && $email !== '') {
				$emails[$userId] = $email;
			}
		}
		return $emails;
	}

	/** @return array<string, string|null> Eligible users and their current e-mail address, if any. */
	public function getRecipientsForNode(Node $node, int $event, ?string $actorUserId = null): array {
		if (($event & self::ALLOWED_EVENTS) !== $event || $event < 1) {
			return [];
		}

		$nodeIds = [];
		$current = $node;
		$depth = 0;
		while ($current !== null && $depth < 101) {
			$nodeIds[] = (int)$current->getId();
			try {
				$current = $current->getParent();
			} catch (\Throwable $e) {
				$current = null;
			}
			$depth++;
		}

		$nodeIds = array_values(array_unique(array_filter($nodeIds, fn (int $id): bool => $id > 0)));
		if ($nodeIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive')
			->from('abonnieren_object_rules')
			->where($qb->expr()->in(
				'node_id',
				$qb->createNamedParameter($nodeIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$exactId = $nodeIds[0];
		$directParentId = $nodeIds[1] ?? null;
		$recipients = [];
		foreach ($rows as $row) {
			$userId = (string)$row['user_id'];
			$ruleNodeId = (int)$row['node_id'];
			$matchesScope = $ruleNodeId === $exactId
				|| $ruleNodeId === $directParentId
				|| ((bool)$row['recursive'] && in_array($ruleNodeId, $nodeIds, true));
			if (!$matchesScope || (((int)$row['event_mask'] & $event) === 0) || $userId === $actorUserId) {
				continue;
			}
			// A deleted object is no longer resolvable, but its exact rule must
			// still receive the deletion event before the listener removes it.
			if (!($event === self::EVENT_DELETION && $ruleNodeId === $exactId)) {
				try {
					if ($this->rootFolder->getUserFolder($userId)->getById($ruleNodeId) === []) {
						continue;
					}
				} catch (\Throwable $e) {
					continue;
				}
			}

			$email = $this->userManager->get($userId)?->getEMailAddress();
			$recipients[$userId] = is_string($email) && $email !== '' ? $email : null;
		}

		return $recipients;
	}

	private function validateRule(string $userId, int $nodeId, int $eventMask): void {
		if ($nodeId < 1) {
			throw new \InvalidArgumentException('Invalid node ID');
		}
		if ($eventMask < 1 || ($eventMask & ~self::ALLOWED_EVENTS) !== 0) {
			throw new \InvalidArgumentException('Invalid notification event mask');
		}

		$email = $this->userManager->get($userId)?->getEMailAddress();
		if (!is_string($email) || $email === '') {
			throw new \InvalidArgumentException('No email address is configured for the authenticated user');
		}
	}

	private function normalizeRow(array $row): array {
		return [
			'userId' => (string)$row['user_id'],
			'nodeId' => (int)$row['node_id'],
			'eventMask' => (int)$row['event_mask'],
			'recursive' => (bool)$row['recursive'],
			'createdAt' => (int)$row['created_at'],
			'updatedAt' => (int)$row['updated_at'],
		];
	}
}
