<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCA\Abonnieren\BackgroundJob\DeliverDigestJob;
use OCA\Abonnieren\BackgroundJob\DeliverUploadBatchJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Persisted email outbox. Claims are atomic to prevent parallel worker sends.
 * SMTP is not transactional: a crash after remote acceptance but before the
 * success update may still cause one duplicate after lease expiry.
 */
class MailOutbox {
	private const TABLE = 'abonnieren_mail_outbox';

	public function __construct(
		private IDBConnection $db,
		private IJobList $jobList,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private SubscriptionService $subscriptions,
		private IMailer $mailer,
		private LoggerInterface $logger,
	) {
	}

	public function enqueue(string $userId, Node $node, int $event, ?string $actor, bool $deleted, string $subject, string $text, string $html, array $quietHours = []): void {
		$parentId = null;
		if ($deleted) {
			try {
				$parentId = (int)$node->getParent()->getId();
			} catch (\Throwable $e) {
				// A missing parent fails closed during worker access checks.
			}
		}

		$quiet = EmailSchedule::validate(
			(string)($quietHours['quietStart'] ?? ''),
			(string)($quietHours['quietEnd'] ?? ''),
			(string)($quietHours['quietTimezone'] ?? 'UTC'),
		);
		// Capture the exact rule that authorized a deletion. The node may be
		// gone and its direct subscription auto-removed before SMTP is sent.
		$quiet['sourceRuleId'] = (int)($quietHours['ruleNodeId'] ?? 0);
		$quiet['sourceRuleHash'] = (string)($quietHours['ruleHash'] ?? '');
		$quiet['sourceRuleExpiresAt'] = (int)($quietHours['expiresAt'] ?? 0);
		$now = time();
		$digestMode = (string)($quietHours['digestMode'] ?? DigestSchedule::INSTANT);
		$digestTime = (string)($quietHours['digestTime'] ?? '08:00');
		$due = DigestSchedule::dueAt($now, $digestMode, $digestTime,
			$quiet['timezone'], $quiet['start'], $quiet['end']);
		if ($digestMode !== DigestSchedule::INSTANT) {
			$key = DigestSchedule::key($userId, $due, $quiet['start'], $quiet['end'], $quiet['timezone']);
			$id = $this->insertRow($userId, $node, $parentId, $event, $actor, $deleted,
				$subject, $text, $html, $now, $due, '', mb_substr($node->getName(), 0, 255),
				$quiet, $key);
			$this->schedule($id, $due, false, true);
			return;
		}
		$quietDue = $due;
		// Quiet-hour notifications are held individually to prevent separate
		// subscription policies from leaking into the same upload summary.
		if ($quiet['start'] === '' && $event === SubscriptionService::EVENT_UPLOAD && $node instanceof File && $actor !== null && $actor !== '') {
			try {
				$folderId = (int)$node->getParent()->getId();
				if ($folderId > 0) {
					$reserved = (new UploadBatchCoordinator($this->db))->add(
						$userId, $actor, $folderId, $now,
						fn (string $key, int $due): int => $this->insertRow(
							$userId, $node, $parentId, $event, $actor, $deleted, $subject, $text, $html,
							$now, $due, $key, mb_substr($node->getName(), 0, 255),
						),
					);
					$this->schedule($reserved['id'], $reserved['due'], true);
					return;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Upload grouping unavailable; sending an individual notification', [
					'app' => 'abonnieren', 'nodeId' => (int)$node->getId(), 'exception' => $e,
				]);
			}
		}

		$id = $this->insertRow($userId, $node, $parentId, $event, $actor, $deleted,
			$subject, $text, $html, $now, $quietDue, '', '', $quiet);
		$this->schedule($id, $quietDue);
	}

	private function insertRow(
		string $userId, Node $node, ?int $parentId, int $event, ?string $actor, bool $deleted,
		string $subject, string $text, string $html, int $now, int $due, string $batchKey, string $itemName, array $quiet = [], string $digestKey = '',
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'user_id' => $qb->createNamedParameter($userId),
			'node_id' => $qb->createNamedParameter((int)$node->getId()),
			'parent_id' => $qb->createNamedParameter($parentId),
			'event_mask' => $qb->createNamedParameter($event),
			'actor_id' => $qb->createNamedParameter($actor),
			'deleted' => $qb->createNamedParameter($deleted ? 1 : 0),
			'subject' => $qb->createNamedParameter($subject),
			'body_text' => $qb->createNamedParameter($text),
			'body_html' => $qb->createNamedParameter($html),
			'status' => $qb->createNamedParameter('pending'),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
			'next_attempt_at' => $qb->createNamedParameter($due),
			'attempts' => $qb->createNamedParameter(0),
			'lease_until' => $qb->createNamedParameter(0),
			'completed_at' => $qb->createNamedParameter(0),
			'batch_key' => $qb->createNamedParameter($batchKey),
			'batch_until' => $qb->createNamedParameter($batchKey !== '' ? $due : 0),
			'item_name' => $qb->createNamedParameter($itemName),
			'quiet_start' => $qb->createNamedParameter($quiet['start'] ?? ''),
			'quiet_end' => $qb->createNamedParameter($quiet['end'] ?? ''),
			'quiet_timezone' => $qb->createNamedParameter($quiet['timezone'] ?? 'UTC'),
			'digest_key' => $qb->createNamedParameter($digestKey),
			'source_rule_id' => $qb->createNamedParameter($quiet['sourceRuleId'] ?? 0),
			'source_rule_hash' => $qb->createNamedParameter($quiet['sourceRuleHash'] ?? ''),
			'source_rule_expires_at' => $qb->createNamedParameter($quiet['sourceRuleExpiresAt'] ?? 0),
		]);
		$qb->executeStatement();
		return $this->db->lastInsertId(self::TABLE);
	}

	private function schedule(int $id, int $at, bool $batch = false, bool $digest = false): void {
		try {
			// Arguments may be logged by Nextcloud. Keep sensitive data in DB.
			$this->jobList->scheduleAfter($digest ? DeliverDigestJob::class : ($batch ? DeliverUploadBatchJob::class : DeliverMailJob::class), $at, ['id' => $id]);
		} catch (\Throwable $e) {
			// Outbox row remains pending; periodic recovery will reschedule.
			$this->logger->error('Could not schedule outbox delivery', [
				'app' => 'abonnieren', 'outboxId' => $id, 'exception' => $e,
			]);
		}
	}

	/** Only a worker that atomically claims the due row can contact SMTP. */
	public function deliverById(int $id): void {
		if ($id < 1) {
			return;
		}
		$now = time();
		$token = bin2hex(random_bytes(16));
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('sending'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('lease_token', $qb->createNamedParameter($token))
			->set('lease_until', $qb->createNamedParameter($now + MailRetryPolicy::LEASE_SECONDS))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)));
		if ($qb->executeStatement() !== 1) {
			return; // Already running, completed, cancelled, failed or not yet due.
		}

		$row = $this->find($id);
		if ($row === null) {
			return; // Reconciler will recover the abandoned lease if necessary.
		}
		if ((string)($row['digest_key'] ?? '') !== '') {
			$this->retry($id, $token, (int)$row['attempts'], new \RuntimeException('Digest row routed to regular delivery job'), false, true);
			return;
		}
		if ((string)($row['batch_key'] ?? '') !== '') {
			$this->retry($id, $token, (int)$row['attempts'], new \RuntimeException('Batch row routed to regular delivery job'), true);
			return;
		}
		// Quiet hours can begin while a message is waiting for a worker.
		// Recheck after the atomic claim so we never send in the quiet window.
		$quietDue = EmailSchedule::dueAt(time(),
			(string)($row['quiet_start'] ?? ''), (string)($row['quiet_end'] ?? ''),
			(string)($row['quiet_timezone'] ?? 'UTC'));
		if ($quietDue > time()) {
			$this->deferClaimedForQuietHours($id, $token, $quietDue);
			return;
		}
		$attempts = (int)$row['attempts'];
		$userId = (string)$row['user_id'];
		$deleted = (bool)$row['deleted'];
		$accessId = $deleted ? (int)($row['parent_id'] ?? 0) : (int)$row['node_id'];

		try {
			if ($accessId < 1) {
				$this->finish($id, $token, 'cancelled');
				return;
			}
			$nodes = $this->rootFolder->getUserFolder($userId)->getById($accessId);
			if ($nodes === []) {
				$this->finish($id, $token, 'cancelled');
				return;
			}
			if ($deleted && !$this->deletionStillAuthorized($row)) {
				$this->finish($id, $token, 'cancelled');
				return;
			}
			if (!$deleted && !isset($this->subscriptions->getRecipientEmailsForNode(
				$nodes[0], (int)$row['event_mask'], is_string($row['actor_id']) ? $row['actor_id'] : null,
			)[$userId])) {
				$this->finish($id, $token, 'cancelled');
				return;
			}
			$email = $this->userManager->get($userId)?->getEMailAddress();
			if (!is_string($email) || $email === '') {
				$this->finish($id, $token, 'cancelled');
				return;
			}
		} catch (\Throwable $e) {
			$this->retry($id, $token, $attempts, $e);
			return;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject((string)$row['subject']);
			$message->setPlainBody((string)$row['body_text']);
			$message->setHtmlBody((string)$row['body_html']);
			$failedAddresses = $this->mailer->send($message);
			if ($failedAddresses !== []) {
				throw new \RuntimeException('Mailer reported an unsuccessful delivery');
			}
		} catch (\Throwable $e) {
			$this->retry($id, $token, $attempts, $e);
			return;
		}
		$this->finish($id, $token, 'sent');
	}


	/**
	 * Send all eligible uploads in a closed burst as one mail. Earlier jobs
	 * observe a postponed deadline and return without claiming any rows.
	 * Existing per-recipient permission checks run before summary contents
	 * are generated to avoid disclosing revoked files.
	 */
	public function deliverUploadBatchById(int $id): void {
		$this->deliverGroupedById($id, false);
	}

	public function deliverDigestById(int $id): void {
		$this->deliverGroupedById($id, true);
	}

	private function deliverGroupedById(int $id, bool $digest): void {
		if ($id < 1) {
			return;
		}
		$now = time();
		$groupColumn = $digest ? 'digest_key' : 'batch_key';
		$leader = $this->find($id);
		if ($leader === null || ($leader['status'] ?? '') !== 'pending'
			|| (string)($leader[$groupColumn] ?? '') === ''
			|| (!$digest && (int)$leader['batch_until'] > $now)
			|| (int)$leader['next_attempt_at'] > $now) {
			return;
		}
		$key = (string)$leader[$groupColumn];
		if ($digest) {
			$quietDue = EmailSchedule::dueAt($now,
				(string)($leader['quiet_start'] ?? ''), (string)($leader['quiet_end'] ?? ''),
				(string)($leader['quiet_timezone'] ?? 'UTC'));
			if ($quietDue > $now) {
				// A retry may become due after a quiet period starts.
				// Postpone the entire pending digest without consuming an attempt.
				$delay = $this->db->getQueryBuilder();
				$delay->update(self::TABLE)
					->set('next_attempt_at', $delay->createNamedParameter($quietDue))
					->where($delay->expr()->eq('digest_key', $delay->createNamedParameter($key)))
					->andWhere($delay->expr()->eq('status', $delay->createNamedParameter('pending')))
					->andWhere($delay->expr()->lte('next_attempt_at', $delay->createNamedParameter($now)));
				if ($delay->executeStatement() > 0) {
					$this->schedule($id, $quietDue, false, true);
				}
				return;
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq($groupColumn, $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)))
			->orderBy('id', 'ASC')
			->setMaxResults($digest ? DigestSchedule::MAX_ITEMS_PER_EMAIL : UploadBatch::MAX_ITEMS_PER_EMAIL);
		if (!$digest) {
			$qb->andWhere($qb->expr()->lte('batch_until', $qb->createNamedParameter($now)));
		}
		$result = $qb->executeQuery();
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $result->fetchAll());
		$result->closeCursor();
		if ($ids === []) {
			return;
		}

		$token = bin2hex(random_bytes(16));
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('sending'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('lease_token', $qb->createNamedParameter($token))
			->set('lease_until', $qb->createNamedParameter($now + MailRetryPolicy::LEASE_SECONDS))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)));
		if (!$digest) {
			$qb->andWhere($qb->expr()->lte('batch_until', $qb->createNamedParameter($now)));
		}
		if ($qb->executeStatement() < 1) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq($groupColumn, $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		$result = $qb->executeQuery();
		$claimed = $result->fetchAll();
		$result->closeCursor();

		$valid = [];
		$recipientEmail = null;
		foreach ($claimed as $row) {
			$rowId = (int)$row['id'];
			$attempts = (int)$row['attempts'];
			$userId = (string)$row['user_id'];
			try {
				$deleted = (bool)$row['deleted'];
				$accessId = $deleted ? (int)($row['parent_id'] ?? 0) : (int)$row['node_id'];
				$nodes = $accessId > 0 ? $this->rootFolder->getUserFolder($userId)->getById($accessId) : [];
				if ($nodes === [] || ($deleted && !$this->deletionStillAuthorized($row))
					|| (!$deleted && !isset($this->subscriptions->getRecipientEmailsForNode(
						$nodes[0], (int)$row['event_mask'],
						is_string($row['actor_id']) ? $row['actor_id'] : null,
					)[$userId]))) {
					$this->finish($rowId, $token, 'cancelled');
					continue;
				}
				$email = $this->userManager->get($userId)?->getEMailAddress();
				if (!is_string($email) || $email === '') {
					$this->finish($rowId, $token, 'cancelled');
					continue;
				}
				$recipientEmail = $email;
				$valid[] = $row;
			} catch (\Throwable $e) {
				$this->retry($rowId, $token, $attempts, $e, !$digest, $digest);
			}
		}
		if ($valid === [] || $recipientEmail === null) {
			return;
		}

		if (count($valid) === 1) {
			$subject = (string)$valid[0]['subject'];
			$text = (string)$valid[0]['body_text'];
			$html = (string)$valid[0]['body_html'];
		} else {
			$summary = $digest ? DigestSchedule::message($valid) : UploadBatch::message($valid);
			$subject = $summary['subject'];
			$text = $summary['text'];
			$html = $summary['html'];
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipientEmail]);
			$message->setSubject($subject);
			$message->setPlainBody($text);
			$message->setHtmlBody($html);
			if ($this->mailer->send($message) !== []) {
				throw new \RuntimeException('Mailer reported unsuccessful batch delivery');
			}
			foreach ($valid as $row) {
				$this->finish((int)$row['id'], $token, 'sent');
			}
		} catch (\Throwable $e) {
			foreach ($valid as $row) {
				$this->retry((int)$row['id'], $token, (int)$row['attempts'], $e, !$digest, $digest);
			}
		}
	}

	/**
	 * The deleted node can no longer be resolved. We still need access to its
	 * parent (checked by caller) and evidence of the original subscription.
	 * For ancestor rules, recheck the current rule's full policy fingerprint:
	 * deleting the rule, changing exclusions or snoozing cancels the email.
	 * For direct rules, Nextcloud automatically deletes the rule alongside the
	 * node, so the original authenticated snapshot is the only surviving proof.
	 *
	 * Legacy outbox rows without provenance fail closed.
	 *
	 * @param array<string,mixed> $row
	 */
	private function deletionStillAuthorized(array $row): bool {
		$sourceId = (int)($row['source_rule_id'] ?? 0);
		$nodeId = (int)$row['node_id'];
		$fingerprint = (string)($row['source_rule_hash'] ?? '');
		$expiresAt = (int)($row['source_rule_expires_at'] ?? 0);
		if ($sourceId < 1 || strlen($fingerprint) !== 64
			|| ($expiresAt !== 0 && $expiresAt <= time())) {
			return false;
		}
		if ($sourceId === $nodeId) {
			// The exact rule is already gone, but parent-access and recorded
			// active policy were verified when the original event occurred.
			return true;
		}
		$userId = (string)$row['user_id'];
		$rule = $this->subscriptions->getRule($userId, $sourceId);
		if ($rule === null
			|| (int)$rule['nodeId'] !== $sourceId
			|| ((int)$rule['eventMask'] & SubscriptionService::EVENT_DELETION) === 0
			|| ((int)$rule['channelMask'] & SubscriptionService::CHANNEL_EMAIL) === 0
			|| SubscriptionLifecycle::expired((int)$rule['expiresAt'], time())
			|| SubscriptionLifecycle::snoozed((int)$rule['snoozedUntil'], time())) {
			return false;
		}
		return hash_equals($fingerprint, (string)$rule['ruleHash']);
	}

	/** Does not spend an SMTP attempt when delivery is postponed for quiet hours. */
	private function deferClaimedForQuietHours(int $id, string $token, int $due): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('pending'))
			->set('attempts', $qb->createFunction('attempts - 1'))
			->set('next_attempt_at', $qb->createNamedParameter($due))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		if ($qb->executeStatement() === 1) {
			$this->schedule($id, $due);
		}
	}

	/** @return array<string, mixed>|null */
	private function find(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? $row : null;
	}

	/** Preserve metadata for diagnostics, but clear potentially sensitive bodies. */
	private function finish(int $id, string $token, string $status): void {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($status))
			->set('subject', $qb->createNamedParameter(''))
			->set('body_text', $qb->createNamedParameter(''))
			->set('body_html', $qb->createNamedParameter(''))
			->set('item_name', $qb->createNamedParameter(''))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('completed_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	private function retry(int $id, string $token, int $attempts, \Throwable $e, bool $batch = false, bool $digest = false): void {
		$this->logger->error('Queued subscription email delivery failed', [
			'app' => 'abonnieren', 'outboxId' => $id, 'attempt' => $attempts, 'exception' => $e,
		]);
		if (MailRetryPolicy::exhausted($attempts)) {
			$this->finish($id, $token, 'failed');
			return;
		}
		$due = time() + MailRetryPolicy::delayForAttempt($attempts);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('pending'))
			->set('next_attempt_at', $qb->createNamedParameter($due))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		if ($qb->executeStatement() === 1) {
			$this->schedule($id, $due, $batch, $digest);
		}
	}

	/** Recover orphaned jobs and clear old outcome rows (invoked by timed cron). */
	public function reconcile(): void {
		$now = time();

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'attempts', 'batch_key', 'digest_key')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->lte('lease_until', $qb->createNamedParameter($now)))
			->setMaxResults(100);
		$result = $qb->executeQuery();
		$expired = $result->fetchAll();
		$result->closeCursor();
		foreach ($expired as $row) {
			$id = (int)$row['id'];
			$attempts = (int)$row['attempts'];
			if (MailRetryPolicy::exhausted($attempts)) {
				// Lease recovery must not overwrite a renewed (still active) lease.
				$this->expireFailed($id, $now);
				continue;
			}
			$due = $now + MailRetryPolicy::delayForAttempt($attempts);
			$update = $this->db->getQueryBuilder();
			$update->update(self::TABLE)
				->set('status', $update->createNamedParameter('pending'))
				->set('next_attempt_at', $update->createNamedParameter($due))
				->set('lease_until', $update->createNamedParameter(0))
				->set('lease_token', $update->createNamedParameter(null))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('id', $update->createNamedParameter($id)))
				->andWhere($update->expr()->eq('status', $update->createNamedParameter('sending')))
				->andWhere($update->expr()->lte('lease_until', $update->createNamedParameter($now)));
			if ($update->executeStatement() === 1) {
				$this->schedule($id, $due, !empty($row['batch_key']), !empty($row['digest_key']));
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'batch_key', 'digest_key')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)))
			->setMaxResults(100);
		$result = $qb->executeQuery();
		$dueRows = $result->fetchAll();
		$result->closeCursor();
		foreach ($dueRows as $row) {
			$this->schedule((int)$row['id'], $now, !empty($row['batch_key']), !empty($row['digest_key']));
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				['sent', 'failed', 'cancelled'], IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($now - MailRetryPolicy::HISTORY_SECONDS)))
			->andWhere($qb->expr()->gt('completed_at', $qb->createNamedParameter(0)));
		$qb->executeStatement();
	}

	private function expireFailed(int $id, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('failed'))
			->set('subject', $qb->createNamedParameter(''))
			->set('body_text', $qb->createNamedParameter(''))
			->set('body_html', $qb->createNamedParameter(''))
			->set('item_name', $qb->createNamedParameter(''))
			->set('completed_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('lease_token', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->lte('lease_until', $qb->createNamedParameter($now)));
		$qb->executeStatement();
	}
}
