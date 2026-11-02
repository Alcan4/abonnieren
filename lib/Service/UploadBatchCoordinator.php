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
		// Initialize the scope before opening our transaction. PostgreSQL
		// aborts the *entire transaction* after a duplicate-key insert, even
		// when the constraint exception is caught. Doing this in autocommit
		// mode allows concurrent uploaders to race safely on the unique key.
		// Use the query builder to honor Nextcloud's configured table prefix.
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
			if (!in_array($e->getReason(), [
				\OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
				\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
			], true)) {
				throw $e;
			}
		}

		$this->db->beginTransaction();
		try {
			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
				// SQLite has no SELECT FOR UPDATE. Acquire its serialized writer
				// lock *before* reading the current scope and deadline.
				$lock = $this->db->getQueryBuilder();
				$lock->update(self::SCOPES)
					->set('scope_key', $lock->createNamedParameter($scopeKey))
					->where($lock->expr()->eq('scope_key', $lock->createNamedParameter($scopeKey)));
				$lock->executeStatement();
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::SCOPES)
				->where($qb->expr()->eq('scope_key', $qb->createNamedParameter($scopeKey)));
			// SQLite does not support SELECT FOR UPDATE. The insert above takes
			// SQLite's serialized writer lock inside this transaction instead.
			if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
				$qb->forUpdate();
			}
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
