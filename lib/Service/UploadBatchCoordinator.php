<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\IDBConnection;

/**
 * Serializes uploads sharing a recipient/actor/folder scope.
 * Updating the deadline and persisting a new outbox item share one transaction.
 */
final class UploadBatchCoordinator {
	private const SCOPES = 'abonnieren_upload_scopes';
	private const OUTBOX = 'abonnieren_mail_outbox';

	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param callable(string,int):int $persist Persists one message inside the transaction.
	 * @return array{id:int,due:int}
	 */
	public function add(string $recipient, string $actor, int $parentId, int $now, callable $persist): array {
		$scopeKey = UploadBatch::scopeKey($recipient, $actor, $parentId);
		$this->db->beginTransaction();
		try {
			// Use Nextcloud's query builder so the configured DB table prefix
			// is applied. IDBConnection::insertIfNotExist() works on raw table
			// names and would otherwise target a missing unprefixed table.
			try {
				$insert = $this->db->getQueryBuilder();
				$insert->insert(self::SCOPES)->values([
					'scope_key' => $insert->createNamedParameter($scopeKey),
					'batch_key' => $insert->createNamedParameter(''),
					'started_at' => $insert->createNamedParameter(0),
					'last_at' => $insert->createNamedParameter(0),
					'due_at' => $insert->createNamedParameter(0),
					'item_count' => $insert->createNamedParameter(0),
				]);
				$insert->executeStatement();
			} catch (\OCP\DB\Exception $e) {
				// A simultaneous uploader may have already created the scope.
				if (!in_array($e->getReason(), [
					\OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
					\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				], true)) {
					throw $e;
				}
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::SCOPES)
				->where($qb->expr()->eq('scope_key', $qb->createNamedParameter($scopeKey)))
				->forUpdate();
			$result = $qb->executeQuery();
			$existing = $result->fetch();
			$result->closeCursor();
			if (!is_array($existing)) {
				throw new \RuntimeException('Upload batch scope could not be locked');
			}
			$append = UploadBatch::canAppend($now, (int)$existing['started_at'], (int)$existing['last_at'], (int)$existing['item_count']);
			$startedAt = $append ? (int)$existing['started_at'] : $now;
			$batchKey = $append ? (string)$existing['batch_key'] : bin2hex(random_bytes(32));
			$due = UploadBatch::dueAt($startedAt, $now);

			$qb = $this->db->getQueryBuilder();
			$qb->update(self::SCOPES)
				->set('batch_key', $qb->createNamedParameter($batchKey))
				->set('started_at', $qb->createNamedParameter($startedAt))
				->set('last_at', $qb->createNamedParameter($now))
				->set('due_at', $qb->createNamedParameter($due))
				->set('item_count', $qb->createNamedParameter($append ? (int)$existing['item_count'] + 1 : 1))
				->where($qb->expr()->eq('scope_key', $qb->createNamedParameter($scopeKey)));
			$qb->executeStatement();

			if ($append) {
				// Existing queued jobs can wake up at their old due time, but
				// cannot claim the now-postponed batch before the new deadline.
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::OUTBOX)
					->set('batch_until', $qb->createNamedParameter($due))
					->set('next_attempt_at', $qb->createNamedParameter($due))
					->where($qb->expr()->eq('batch_key', $qb->createNamedParameter($batchKey)))
					->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
					->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter(0)));
				$qb->executeStatement();
			}
			$id = $persist($batchKey, $due);
			$this->db->commit();
			return ['id' => $id, 'due' => $due];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
