<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
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

	public function enqueue(string $userId, Node $node, int $event, ?string $actor, bool $deleted, string $subject, string $text, string $html): void {
		$parentId = null;
		if ($deleted) {
			try {
				$parentId = (int)$node->getParent()->getId();
			} catch (\Throwable $e) {
				// A missing parent fails closed during worker access checks.
			}
		}

		$now = time();
		if ($event === SubscriptionService::EVENT_UPLOAD && $node instanceof File && $actor !== null && $actor !== '') {
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
			$subject, $text, $html, $now, $now, '', '');
		$this->schedule($id, $now);
	}

	private function insertRow(
		string $userId, Node $node, ?int $parentId, int $event, ?string $actor, bool $deleted,
		string $subject, string $text, string $html, int $now, int $due, string $batchKey, string $itemName,
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
		]);
		$qb->executeStatement();
		return $this->db->lastInsertId(self::TABLE);
	}

	private function schedule(int $id, int $at, bool $batch = false): void {
		try {
			// Arguments may be logged by Nextcloud. Keep sensitive data in DB.
			$this->jobList->scheduleAfter($batch ? DeliverUploadBatchJob::class : DeliverMailJob::class, $at, ['id' => $id]);
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
			->set('attempts', 'attempts + 1')
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
		if ($id < 1) {
			return;
		}
		$now = time();
		$leader = $this->find($id);
		if ($leader === null || ($leader['status'] ?? '') !== 'pending'
			|| (string)($leader['batch_key'] ?? '') === ''
			|| (int)$leader['batch_until'] > $now
			|| (int)$leader['next_attempt_at'] > $now) {
			return;
		}
		$key = (string)$leader['batch_key'];

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('batch_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('batch_until', $qb->createNamedParameter($now)))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)))
			->orderBy('id', 'ASC')
			->setMaxResults(UploadBatch::MAX_ITEMS_PER_EMAIL);
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
			->set('attempts', 'attempts + 1')
			->set('lease_token', $qb->createNamedParameter($token))
			->set('lease_until', $qb->createNamedParameter($now + MailRetryPolicy::LEASE_SECONDS))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('batch_until', $qb->createNamedParameter($now)))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)));
		if ($qb->executeStatement() < 1) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('batch_key', $qb->createNamedParameter($key)))
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
				$nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$row['node_id']);
				if ($nodes === [] || !isset($this->subscriptions->getRecipientEmailsForNode(
					$nodes[0], (int)$row['event_mask'],
					is_string($row['actor_id']) ? $row['actor_id'] : null,
				)[$userId])) {
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
				$this->retry($rowId, $token, $attempts, $e, true);
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
			$summary = UploadBatch::message($valid);
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
				$this->retry((int)$row['id'], $token, (int)$row['attempts'], $e, true);
			}
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
			->set('lease_token', $qb->createNamedParameter(null))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('completed_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	private function retry(int $id, string $token, int $attempts, \Throwable $e): void {
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
			$this->schedule($id, $due);
		}
	}

	/** Recover orphaned jobs and clear old outcome rows (invoked by timed cron). */
	public function reconcile(): void {
		$now = time();

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'attempts')->from(self::TABLE)
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
				$this->schedule($id, $due);
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)))
			->setMaxResults(100);
		$result = $qb->executeQuery();
		$dueRows = $result->fetchAll();
		$result->closeCursor();
		foreach ($dueRows as $row) {
			$this->schedule((int)$row['id'], $now);
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
