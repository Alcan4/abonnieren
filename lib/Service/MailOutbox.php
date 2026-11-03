<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
				// Missing parent must fail closed when we check access.
			}
		}
		$now = time();
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
			'next_attempt_at' => $qb->createNamedParameter($now),
			'attempts' => $qb->createNamedParameter(0),
			'lease_until' => $qb->createNamedParameter(0),
			'completed_at' => $qb->createNamedParameter(0),
		]);
		$qb->executeStatement();
		$id = $this->db->lastInsertId(self::TABLE);
		$this->schedule($id, $now);
	}

	private function schedule(int $id, int $at): void {
		try {
			// Arguments may be logged by Nextcloud. Keep sensitive data in DB.
			$this->jobList->scheduleAfter(DeliverMailJob::class, $at, ['id' => $id]);
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
		$attempts = (int)$row['attempts'];
		try {
			$email = $this->authorizedDelivery($row);
			if ($email === null) {
				$this->finish($id, $token, 'cancelled');
				return;
			}
		} catch (\Throwable $e) {
			$this->retry($id, $token, $attempts, $e);
			return;
		}

		try {
			$this->sendMessage($email, (string)$row['subject'],
				(string)$row['body_text'], (string)$row['body_html']);
		} catch (\Throwable $e) {
			$this->retry($id, $token, $attempts, $e);
			return;
		}
		$this->finish($id, $token, 'sent');
	}

	/**
	 * Try every recipient-visible mount because the same file can be shared at
	 * multiple paths and only one of those paths may still match a subscription.
	 *
	 * @param array<string,mixed> $row
	 */
	private function authorizedDelivery(array $row): ?string {
		$userId = (string)$row['user_id'];
		$deleted = (bool)$row['deleted'];
		$accessId = $deleted ? (int)($row['parent_id'] ?? 0) : (int)$row['node_id'];
		if ($accessId < 1) {
			return null;
		}
		$nodes = $this->rootFolder->getUserFolder($userId)->getById($accessId);
		if ($nodes === []) {
			return null;
		}
		if (!$deleted) {
			$authorized = false;
			foreach ($nodes as $node) {
				if (isset($this->subscriptions->getRecipientEmailsForNode(
					$node,
					(int)$row['event_mask'],
					is_string($row['actor_id']) ? $row['actor_id'] : null,
				)[$userId])) {
					$authorized = true;
					break;
				}
			}
			if (!$authorized) {
				return null;
			}
		}
		$email = $this->userManager->get($userId)?->getEMailAddress();
		return is_string($email) && $email !== '' ? $email : null;
	}

	private function sendMessage(string $email, string $subject, string $text, string $html): void {
		$message = $this->mailer->createMessage();
		$message->setTo([$email]);
		$message->setSubject($subject);
		$message->setPlainBody($text);
		$message->setHtmlBody($html);
		if ($this->mailer->send($message) !== []) {
			throw new \RuntimeException('Mailer reported an unsuccessful delivery');
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
		$this->terminalize($id, $status, time(), $token);
	}

	private function retry(int $id, string $token, int $attempts, \Throwable $e): void {
		$this->logger->error('Queued subscription email delivery failed', [
			'app' => 'abonnieren', 'outboxId' => $id, 'attempt' => $attempts, 'exception' => $e,
		]);
		if (MailRetryPolicy::exhausted($attempts)) {
			$this->finish($id, $token, 'failed');
			return;
		}
		$this->requeueSending($id, $attempts, time(), $token);
	}

	private function requeueSending(int $id, int $attempts, int $now, ?string $token = null): void {
		$due = $now + MailRetryPolicy::delayForAttempt($attempts);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('pending'))
			->set('next_attempt_at', $qb->createNamedParameter($due))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')));
		if ($token !== null) {
			$qb->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		} else {
			$qb->andWhere($qb->expr()->lte('lease_until', $qb->createNamedParameter($now)));
		}
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
			$this->requeueSending($id, $attempts, $now);
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
		$this->terminalize($id, 'failed', $now);
	}

	private function terminalize(int $id, string $status, int $now, ?string $token = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($status))
			->set('subject', $qb->createNamedParameter(''))
			->set('body_text', $qb->createNamedParameter(''))
			->set('body_html', $qb->createNamedParameter(''))
			->set('completed_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('lease_until', $qb->createNamedParameter(0))
			->set('lease_token', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')));
		if ($token !== null) {
			$qb->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)));
		} else {
			$qb->andWhere($qb->expr()->lte('lease_until', $qb->createNamedParameter($now)));
		}
		$qb->executeStatement();
	}
}
