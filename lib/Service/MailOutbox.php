<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** Database-backed mail outbox; job arguments contain only the opaque row ID. */
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
				// A missing parent fails closed during delivery.
			}
		}

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
			'created_at' => $qb->createNamedParameter(time()),
		]);
		$qb->executeStatement();
		$this->jobList->add(DeliverMailJob::class, ['id' => $this->db->lastInsertId(self::TABLE)]);
	}

	public function deliverById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row) || $row['status'] !== 'pending') {
			return;
		}
		$userId = (string)$row['user_id'];
		$deleted = (bool)$row['deleted'];
		$accessId = $deleted ? (int)($row['parent_id'] ?? 0) : (int)$row['node_id'];

		try {
			if ($accessId < 1) {
				$this->discard($id);
				return;
			}
			$nodes = $this->rootFolder->getUserFolder($userId)->getById($accessId);
			if ($nodes === []) {
				$this->discard($id);
				return;
			}
			if (!$deleted && !isset($this->subscriptions->getRecipientEmailsForNode(
				$nodes[0], (int)$row['event_mask'], is_string($row['actor_id']) ? $row['actor_id'] : null,
			)[$userId])) {
				$this->discard($id);
				return;
			}
			$email = $this->userManager->get($userId)?->getEMailAddress();
			if (!is_string($email) || $email === '') {
				$this->discard($id);
				return;
			}
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject((string)$row['subject']);
			$message->setBody((string)$row['body_text'], 'text/plain');
			$message->setHtmlBody((string)$row['body_html']);
			$this->mailer->send($message);
			$this->discard($id);
		} catch (\Throwable $e) {
			$this->setStatus($id, 'failed');
			$this->logger->error('Could not deliver queued subscription mail', [
				'app' => 'abonnieren', 'outboxId' => $id, 'exception' => $e,
			]);
		}
	}

	private function discard(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	private function setStatus(int $id, string $status): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('status', $qb->createNamedParameter($status))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}
