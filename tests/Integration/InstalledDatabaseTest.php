<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Integration;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCA\Abonnieren\Service\UploadBatchCoordinator;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Executes against the real, installed Nextcloud DB (SQLite in CI).
 *
 * Test records use unique non-existent UIDs/node IDs and are deleted in finally;
 * no real user or SMTP transport is used.
 */
final class InstalledDatabaseTest extends TestCase {
	private IDBConnection $db;
	private string $uid;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->uid = 'abo_ci_' . bin2hex(random_bytes(6));
	}

	protected function tearDown(): void {
		foreach (['abonnieren_mail_outbox', 'abonnieren_object_rules'] as $table) {
			$q = $this->db->getQueryBuilder();
			$q->delete($table)->where($q->expr()->eq('user_id', $q->createNamedParameter($this->uid)));
			$q->executeStatement();
		}
		parent::tearDown();
	}

	private function row(string $table, string $column, mixed $value): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($table)
			->where($q->expr()->eq($column, $q->createNamedParameter($value)))
			->setMaxResults(1);
		$result = $q->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? $row : null;
	}

	public function testAllStackedDatabaseMigrationsInstalled(): void {
		// These select columns were introduced by independent migration steps.
		$q = $this->db->getQueryBuilder();
		$q->select('excluded_folders', 'expires_at', 'snoozed_until', 'digest_mode',
			'quiet_start', 'channel_mask', 'debounce_seconds')
			->from('abonnieren_object_rules')->setMaxResults(1);
		$r = $q->executeQuery();
		$r->closeCursor();

		$q = $this->db->getQueryBuilder();
		$q->select('digest_key', 'batch_key', 'next_attempt_at', 'lease_token', 'quiet_start')
			->from('abonnieren_mail_outbox')->setMaxResults(1);
		$r = $q->executeQuery();
		$r->closeCursor();

		$q = $this->db->getQueryBuilder();
		$q->select('scope_key', 'batch_key', 'due_at')
			->from('abonnieren_upload_scopes')->setMaxResults(1);
		$r = $q->executeQuery();
		$r->closeCursor();
		self::assertTrue(true);
	}

	public function testStoredSubscriptionPolicyIsResolvedViaRealDatabase(): void {
		$nodeId = 7200000 + random_int(1, 100000);
		$q = $this->db->getQueryBuilder();
		$q->insert('abonnieren_object_rules')->values([
			'user_id' => $q->createNamedParameter($this->uid),
			'node_id' => $q->createNamedParameter($nodeId),
			'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_MODIFICATION),
			'recursive' => $q->createNamedParameter(1),
			'debounce_seconds' => $q->createNamedParameter(60),
			'excluded_extensions' => $q->createNamedParameter('tmp'),
			'excluded_folders' => $q->createNamedParameter('Archive'),
			'channel_mask' => $q->createNamedParameter(3),
			'quiet_start' => $q->createNamedParameter(''),
			'quiet_end' => $q->createNamedParameter(''),
			'quiet_timezone' => $q->createNamedParameter('UTC'),
			'digest_mode' => $q->createNamedParameter('instant'),
			'digest_time' => $q->createNamedParameter('08:00'),
			'expires_at' => $q->createNamedParameter(0),
			'snoozed_until' => $q->createNamedParameter(0),
			'created_at' => $q->createNamedParameter(time()),
			'updated_at' => $q->createNamedParameter(time()),
		]);
		$q->executeStatement();

		$ancestor = $this->createMock(Folder::class);
		$ancestor->method('getId')->willReturn($nodeId);
		$ancestor->method('getName')->willReturn('Subscribed');
		$ancestor->method('getParent')->willThrowException(new \RuntimeException('root'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($nodeId + 1);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getParent')->willReturn($ancestor);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with($nodeId)->willReturn([$ancestor]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);

		$service = new SubscriptionService($this->db, $users, $root);
		$policies = $service->getRecipientPoliciesForNode($file, SubscriptionService::EVENT_MODIFICATION);
		self::assertSame(60, $policies[$this->uid]['debounceSeconds']);
		self::assertSame(['Archive'], $policies[$this->uid]['excludedFolders']);
		self::assertSame(3, $policies[$this->uid]['channelMask']);
		self::assertSame('integration@example.test',
			$service->getRecipientEmailsForNode($file, SubscriptionService::EVENT_MODIFICATION)[$this->uid]);
	}

	public function testRealDatabaseClaimDeliversOnceAndClearsMailContent(): void {
		$nodeId = 7300000 + random_int(1, 100000);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($nodeId);
		$node->method('getName')->willReturn('report.pdf');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter');
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with($nodeId)->willReturn([$node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')->willReturn([$this->uid => 'integration@example.test']);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send')->willReturn([]);

		$outbox = new MailOutbox($this->db, $jobs, $root, $users,
			$subscriptions, $mailer, $this->createMock(LoggerInterface::class));
		$outbox->enqueue($this->uid, $node, SubscriptionService::EVENT_MODIFICATION,
			'another-editor', false, 'Modified', 'private plain body', '<p>private html body</p>');
		$row = $this->row('abonnieren_mail_outbox', 'user_id', $this->uid);
		self::assertNotNull($row);
		self::assertSame('pending', $row['status']);
		$outbox->deliverById((int)$row['id']);
		$outbox->deliverById((int)$row['id']); // Duplicate scheduled jobs must not re-send.
		$final = $this->row('abonnieren_mail_outbox', 'id', (int)$row['id']);
		self::assertSame('sent', $final['status']);
		self::assertSame(1, (int)$final['attempts']);
		self::assertSame('', $final['body_text']);
		self::assertSame('', $final['body_html']);
	}

	public function testSlidingUploadDeadlineUpdatesPreviousRowsInsideDatabase(): void {
		$scope = new UploadBatchCoordinator($this->db);
		$folderId = 7400000 + random_int(1, 100000);
		$now = time();
		$insert = function (string $key, int $due, int $nodeId, int $time): int {
			$q = $this->db->getQueryBuilder();
			$q->insert('abonnieren_mail_outbox')->values([
				'user_id' => $q->createNamedParameter($this->uid),
				'node_id' => $q->createNamedParameter($nodeId),
				'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_UPLOAD),
				'deleted' => $q->createNamedParameter(0),
				'subject' => $q->createNamedParameter('Uploaded'),
				'body_text' => $q->createNamedParameter('new file'),
				'body_html' => $q->createNamedParameter('<p>new file</p>'),
				'status' => $q->createNamedParameter('pending'),
				'created_at' => $q->createNamedParameter($time),
				'updated_at' => $q->createNamedParameter($time),
				'next_attempt_at' => $q->createNamedParameter($due),
				'batch_key' => $q->createNamedParameter($key),
				'batch_until' => $q->createNamedParameter($due),
			]);
			$q->executeStatement();
			return $this->db->lastInsertId('abonnieren_mail_outbox');
		};
		$first = $scope->add($this->uid, 'editor', $folderId, $now,
			fn (string $key, int $due): int => $insert($key, $due, 101, $now));
		$second = $scope->add($this->uid, 'editor', $folderId, $now + 30,
			fn (string $key, int $due): int => $insert($key, $due, 102, $now + 30));
		self::assertSame($now + 90, $first['due']);
		self::assertSame($now + 120, $second['due']);
		$original = $this->row('abonnieren_mail_outbox', 'id', $first['id']);
		self::assertSame($now + 120, (int)$original['next_attempt_at']);
		self::assertSame($now + 120, (int)$original['batch_until']);

		$q = $this->db->getQueryBuilder();
		$q->delete('abonnieren_upload_scopes')
			->where($q->expr()->eq('scope_key',
				$q->createNamedParameter(\OCA\Abonnieren\Service\UploadBatch::scopeKey($this->uid, 'editor', $folderId))));
		$q->executeStatement();
	}
	public function testRealDatabaseSmtpFailureIsRetriedWithoutImmediateRepeat(): void {
		$nodeId = 7500000 + random_int(1, 100000);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($nodeId);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->exactly(2))->method('scheduleAfter');
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with($nodeId)->willReturn([$node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')->willReturn([$this->uid => 'integration@example.test']);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send')
			->willThrowException(new \RuntimeException('Simulated SMTP failure'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$outbox = new MailOutbox($this->db, $jobs, $root, $users, $subscriptions, $mailer, $logger);
		$outbox->enqueue($this->uid, $node, SubscriptionService::EVENT_MODIFICATION,
			'editor', false, 'Modified', 'body', '<p>body</p>');
		$row = $this->row('abonnieren_mail_outbox', 'user_id', $this->uid);
		self::assertNotNull($row);
		$outbox->deliverById((int)$row['id']);
		$outbox->deliverById((int)$row['id']); // An old job cannot bypass the delay.
		$retry = $this->row('abonnieren_mail_outbox', 'id', (int)$row['id']);
		self::assertSame('pending', $retry['status']);
		self::assertSame(1, (int)$retry['attempts']);
		self::assertGreaterThan(time(), (int)$retry['next_attempt_at']);
		self::assertSame('body', $retry['body_text']); // Needed for retry.
	}

	public function testRealDatabaseGroupedWorkerSendsOnlyOneSummary(): void {
		$groupKey = bin2hex(random_bytes(32));
		$now = time() - 3;
		$ids = [];
		foreach (['alpha.pdf', '<beta>.pdf'] as $name) {
			$q = $this->db->getQueryBuilder();
			$q->insert('abonnieren_mail_outbox')->values([
				'user_id' => $q->createNamedParameter($this->uid),
				'node_id' => $q->createNamedParameter(7600000 + count($ids)),
				'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_UPLOAD),
				'deleted' => $q->createNamedParameter(0),
				'subject' => $q->createNamedParameter('File uploaded'),
				'body_text' => $q->createNamedParameter('body'),
				'body_html' => $q->createNamedParameter('<p>body</p>'),
				'status' => $q->createNamedParameter('pending'),
				'created_at' => $q->createNamedParameter($now - 100),
				'updated_at' => $q->createNamedParameter($now),
				'next_attempt_at' => $q->createNamedParameter($now),
				'batch_key' => $q->createNamedParameter($groupKey),
				'batch_until' => $q->createNamedParameter($now),
				'item_name' => $q->createNamedParameter($name),
			]);
			$q->executeStatement();
			$ids[] = $this->db->lastInsertId('abonnieren_mail_outbox');
		}

		$node = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')->willReturn([$this->uid => 'integration@example.test']);

		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setSubject')->with('2 files uploaded to a subscribed folder');
		$message->expects($this->once())->method('setHtmlBody')->with(self::stringContains('&lt;beta&gt;.pdf'));
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message)->willReturn([]);
		$outbox = new MailOutbox($this->db, $this->createMock(IJobList::class),
			$root, $users, $subscriptions, $mailer, $this->createMock(LoggerInterface::class));
		$outbox->deliverUploadBatchById($ids[0]);
		$outbox->deliverUploadBatchById($ids[1]);
		foreach ($ids as $id) {
			$row = $this->row('abonnieren_mail_outbox', 'id', $id);
			self::assertSame('sent', $row['status']);
			self::assertSame(1, (int)$row['attempts']);
			self::assertSame('', $row['item_name']);
		}
	}

	/**
	 * A queued deletion can outlive the parent subscription that requested it.
	 * Do not send the cached deleted filename after the user unsubscribes.
	 */
	public function testQueuedDeletionCancelsAfterParentSubscriptionIsRemoved(): void {
		$parentId = 7800000 + random_int(1, 100000);
		$nodeId = $parentId + 1;
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn($parentId);
		$parent->method('getName')->willReturn('Subscribed');
		$parent->method('getParent')->willThrowException(new \RuntimeException('root'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($nodeId);
		$file->method('getName')->willReturn('private-deleted.pdf');
		$file->method('getParent')->willReturn($parent);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			static fn (int $id): array => $id === $parentId ? [$parent] : [],
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$subscriptions = new SubscriptionService($this->db, $users, $root);
		$subscriptions->upsertRule($this->uid, $parentId, SubscriptionService::EVENT_DELETION, true);
		$policies = $subscriptions->getRecipientPoliciesForNode($file, SubscriptionService::EVENT_DELETION);
		self::assertSame($parentId, $policies[$this->uid]['ruleNodeId']);
		self::assertSame(64, strlen($policies[$this->uid]['ruleHash']));

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter');
		$outbox = new MailOutbox($this->db, $jobs, $root, $users,
			$subscriptions, $mailer, $this->createMock(LoggerInterface::class));
		$outbox->enqueue($this->uid, $file, SubscriptionService::EVENT_DELETION,
			'editor', true, 'File deleted', 'private filename', '<p>private filename</p>',
			$policies[$this->uid]);
		$row = $this->row('abonnieren_mail_outbox', 'user_id', $this->uid);
		self::assertSame($parentId, (int)$row['source_rule_id']);

		$subscriptions->deleteRule($this->uid, $parentId);
		$outbox->deliverById((int)$row['id']);
		$done = $this->row('abonnieren_mail_outbox', 'id', (int)$row['id']);
		self::assertSame('cancelled', $done['status']);
		self::assertSame('', $done['body_text']);
	}

	public function testDeletedFileDirectSubscriptionStillDeliversAfterAutomaticRuleCleanup(): void {
		$parentId = 7900000 + random_int(1, 100000);
		$nodeId = $parentId + 1;
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn($parentId);
		$parent->method('getName')->willReturn('Visible');
		$parent->method('getParent')->willThrowException(new \RuntimeException('root'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($nodeId);
		$file->method('getName')->willReturn('deleted.txt');
		$file->method('getParent')->willReturn($parent);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			static fn (int $id): array => $id === $parentId ? [$parent] : [],
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with($this->uid)->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('integration@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($this->uid)->willReturn($user);
		$subscriptions = new SubscriptionService($this->db, $users, $root);
		$subscriptions->upsertRule($this->uid, $nodeId, SubscriptionService::EVENT_DELETION, false);
		$policies = $subscriptions->getRecipientPoliciesForNode($file, SubscriptionService::EVENT_DELETION);
		self::assertSame($nodeId, $policies[$this->uid]['ruleNodeId']);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send')->willReturn([]);
		$outbox = new MailOutbox($this->db, $this->createMock(IJobList::class),
			$root, $users, $subscriptions, $mailer, $this->createMock(LoggerInterface::class));
		$outbox->enqueue($this->uid, $file, SubscriptionService::EVENT_DELETION,
			'editor', true, 'File deleted', 'original content', '<p>original content</p>',
			$policies[$this->uid]);
		$row = $this->row('abonnieren_mail_outbox', 'user_id', $this->uid);
		self::assertSame($nodeId, (int)$row['source_rule_id']);
		$subscriptions->deleteRulesForNode($nodeId);
		$outbox->deliverById((int)$row['id']);
		self::assertSame('sent',
			$this->row('abonnieren_mail_outbox', 'id', (int)$row['id'])['status']);
	}

}
