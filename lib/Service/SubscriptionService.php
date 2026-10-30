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
	public const DEBOUNCE_SECONDS = SubscriptionPreferences::DEFAULT_DEBOUNCE_SECONDS;
	public const CHANNEL_EMAIL = 1;
	public const CHANNEL_NOTIFICATION = 2;
	public const ALLOWED_CHANNELS = 3;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}

	public function upsertRule(string $userId, int $nodeId, int $eventMask, bool $recursive, int $debounceSeconds = self::DEBOUNCE_SECONDS, string $excludedExtensions = '', int $channelMask = self::CHANNEL_EMAIL, string $quietStart = '', string $quietEnd = '', string $quietTimezone = 'UTC'): void {
		$this->validateRule($userId, $nodeId, $eventMask, $channelMask);
		$debounceSeconds = SubscriptionPreferences::validateDebounce($debounceSeconds);
		$excludedExtensions = SubscriptionPreferences::normalizeExtensions($excludedExtensions);
		$quiet = EmailSchedule::validate($quietStart, $quietEnd, $quietTimezone);

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
				'debounce_seconds' => $qb->createNamedParameter($debounceSeconds),
				'excluded_extensions' => $qb->createNamedParameter($excludedExtensions),
				'channel_mask' => $qb->createNamedParameter($channelMask),
				'quiet_start' => $qb->createNamedParameter($quiet['start']),
				'quiet_end' => $qb->createNamedParameter($quiet['end']),
				'quiet_timezone' => $qb->createNamedParameter($quiet['timezone']),
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
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive', 'debounce_seconds', 'excluded_extensions', 'channel_mask', 'quiet_start', 'quiet_end', 'quiet_timezone', 'created_at', 'updated_at')
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
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive', 'debounce_seconds', 'excluded_extensions', 'channel_mask', 'quiet_start', 'quiet_end', 'quiet_timezone', 'created_at', 'updated_at')
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
		foreach ($this->getRecipientPoliciesForNode($node, $event, $actorUserId) as $userId => $settings) {
			if (($settings['channelMask'] & self::CHANNEL_EMAIL) !== 0 && $settings['email'] !== '') {
				$emails[$userId] = $settings['email'];
			}
		}
		return $emails;
	}

	/**
	 * One winning rule per subscriber. The closest matching rule overrides
	 * ancestor preferences, including when that rule excludes the extension.
	 *
	 * @return array<string,array{email:string,debounceSeconds:int,excludedExtensions:list<string>,channelMask:int,quietStart:string,quietEnd:string,quietTimezone:string}>
	 */
	public function getRecipientPoliciesForNode(Node $node, int $event, ?string $actorUserId = null): array {
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
		$qb->select('user_id', 'node_id', 'event_mask', 'recursive', 'debounce_seconds', 'excluded_extensions', 'channel_mask', 'quiet_start', 'quiet_end', 'quiet_timezone')
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
		$selected = [];
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

			$distance = array_search($ruleNodeId, $nodeIds, true);
			if (!is_int($distance) || (isset($selected[$userId]) && $distance >= $selected[$userId]['distance'])) {
				continue;
			}
			$user = $this->userManager->get($userId);
			if ($user === null) {
				continue;
			}
			$email = $user->getEMailAddress();
			$email = is_string($email) ? $email : '';
			$channels = (int)$row['channel_mask'];
			if ($email === '' && ($channels & self::CHANNEL_NOTIFICATION) === 0) {
				continue;
			}
			$selected[$userId] = [
				'email' => $email,
				'channelMask' => $channels,
				'quietStart' => (string)$row['quiet_start'],
				'quietEnd' => (string)$row['quiet_end'],
				'quietTimezone' => (string)$row['quiet_timezone'],
				'debounceSeconds' => (int)$row['debounce_seconds'],
				'excludedExtensions' => SubscriptionPreferences::parseExtensions((string)$row['excluded_extensions']),
				'distance' => $distance,
			];
		}

		$recipients = [];
		foreach ($selected as $userId => $settings) {
			if (SubscriptionPreferences::isExcluded($node, $settings['excludedExtensions'])) {
				continue;
			}
			unset($settings['distance']);
			$recipients[$userId] = $settings;
		}
		return $recipients;
	}

	private function validateRule(string $userId, int $nodeId, int $eventMask, int $channelMask): void {
		if ($nodeId < 1) {
			throw new \InvalidArgumentException('Invalid node ID');
		}
		if ($eventMask < 1 || ($eventMask & ~self::ALLOWED_EVENTS) !== 0) {
			throw new \InvalidArgumentException('Invalid notification event mask');
		}

		if ($channelMask < 1 || ($channelMask & ~self::ALLOWED_CHANNELS) !== 0) {
			throw new \InvalidArgumentException('Select at least one notification channel');
		}
		$email = $this->userManager->get($userId)?->getEMailAddress();
		if (($channelMask & self::CHANNEL_EMAIL) !== 0 && (!is_string($email) || $email === '')) {
			throw new \InvalidArgumentException('No email address is configured for the authenticated user');
		}
	}

	private function normalizeRow(array $row): array {
		return [
			'userId' => (string)$row['user_id'],
			'nodeId' => (int)$row['node_id'],
			'eventMask' => (int)$row['event_mask'],
			'recursive' => (bool)$row['recursive'],
			'debounceSeconds' => (int)$row['debounce_seconds'],
			'excludedExtensions' => SubscriptionPreferences::parseExtensions((string)$row['excluded_extensions']),
			'channelMask' => (int)$row['channel_mask'],
			'quietStart' => (string)$row['quiet_start'],
			'quietEnd' => (string)$row['quiet_end'],
			'quietTimezone' => (string)$row['quiet_timezone'],
			'createdAt' => (int)$row['created_at'],
			'updatedAt' => (int)$row['updated_at'],
		];
	}
}
