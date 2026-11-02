<?php
declare(strict_types=1);
namespace OCA\Abonnieren\Tests\Integration;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
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

final class OutboxRecoveryIntegrationTest extends TestCase {
	public function testTemporarySmtpFailureThenSuccessfulRetry(): void {
		$db = \OC::$server->get(IDBConnection::class);
		$uid = 'abo_ci_retry_' . bin2hex(random_bytes(6));
		$id = 0;
		try {
			$q = $db->getQueryBuilder();
			$q->insert('abonnieren_mail_outbox')->values([
				'user_id' => $q->createNamedParameter($uid),
				'node_id' => $q->createNamedParameter(965000),
				'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_MODIFICATION),
				'deleted' => $q->createNamedParameter(0),
				'subject' => $q->createNamedParameter('Test modification'),
				'body_text' => $q->createNamedParameter('Synthetic body'),
				'body_html' => $q->createNamedParameter('<p>Synthetic body</p>'),
				'status' => $q->createNamedParameter('pending'),
				'created_at' => $q->createNamedParameter(time() - 5),
				'updated_at' => $q->createNamedParameter(time() - 5),
				'next_attempt_at' => $q->createNamedParameter(time() - 5),
			]);
			$q->executeStatement();
			$id = $db->lastInsertId('abonnieren_mail_outbox');
			$node = $this->createMock(File::class);
			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturn([$node]);
			$root = $this->createMock(IRootFolder::class);
			$root->method('getUserFolder')->willReturn($folder);
			$user = $this->createMock(IUser::class);
			$user->method('getEMailAddress')->willReturn('example@example.test');
			$users = $this->createMock(IUserManager::class);
			$users->method('get')->willReturn($user);
			$subs = $this->createMock(SubscriptionService::class);
			$subs->method('getRecipientEmailsForNode')->willReturn([$uid => 'example@example.test']);
			$mail = $this->createMock(IMailer::class);
			$mail->method('createMessage')->willReturn($this->createMock(IMessage::class));
			$count = 0;
			$mail->expects($this->exactly(2))->method('send')->willReturnCallback(
				static function () use (&$count): array {
					if (++$count === 1) {
						throw new \RuntimeException('Simulated SMTP unavailable');
					}
					return [];
				},
			);
			$jobs = $this->createMock(IJobList::class);
			$jobs->expects($this->once())->method('scheduleAfter');
			$outbox = new MailOutbox($db, $jobs, $root, $users, $subs, $mail, $this->createMock(LoggerInterface::class));
			$outbox->deliverById($id);
			$outbox->deliverById($id);
			self::assertSame(1, $count, 'A stale scheduled job cannot bypass retry delay');
			$q = $db->getQueryBuilder();
			$q->update('abonnieren_mail_outbox')
				->set('next_attempt_at', $q->createNamedParameter(time() - 1))
				->where($q->expr()->eq('id', $q->createNamedParameter($id)));
			$q->executeStatement();
			$outbox->deliverById($id);
			$outbox->deliverById($id);
			$q = $db->getQueryBuilder();
			$q->select('status', 'attempts', 'body_text')->from('abonnieren_mail_outbox')
				->where($q->expr()->eq('id', $q->createNamedParameter($id)));
			$result = $q->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			self::assertSame(2, $count);
			self::assertSame('sent', $row['status']);
			self::assertSame(2, (int)$row['attempts']);
			self::assertSame('', $row['body_text']);
		} finally {
			$q = $db->getQueryBuilder();
			$q->delete('abonnieren_mail_outbox')
				->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
			$q->executeStatement();
		}
	}
	public function testRevokedFileAccessCancelsQueuedEmailAndClearsPayload(): void {
		$db = \OC::$server->get(IDBConnection::class);
		$uid = 'abo_ci_revoked_' . bin2hex(random_bytes(5));
		try {
			$q = $db->getQueryBuilder();
			$q->insert('abonnieren_mail_outbox')->values([
				'user_id' => $q->createNamedParameter($uid),
				'node_id' => $q->createNamedParameter(965001),
				'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_MODIFICATION),
				'deleted' => $q->createNamedParameter(0),
				'subject' => $q->createNamedParameter('Synthetic change'),
				'body_text' => $q->createNamedParameter('Synthetic content'),
				'body_html' => $q->createNamedParameter('<p>Synthetic content</p>'),
				'status' => $q->createNamedParameter('pending'),
				'created_at' => $q->createNamedParameter(time() - 10),
				'updated_at' => $q->createNamedParameter(time() - 10),
				'next_attempt_at' => $q->createNamedParameter(time() - 10),
			]);
			$q->executeStatement();
			$id = $db->lastInsertId('abonnieren_mail_outbox');
			$folder = $this->createMock(Folder::class);
			$folder->expects($this->once())->method('getById')->with(965001)->willReturn([]);
			$root = $this->createMock(IRootFolder::class);
			$root->method('getUserFolder')->with($uid)->willReturn($folder);
			$mail = $this->createMock(IMailer::class);
			$mail->expects($this->never())->method('send');
			$outbox = new MailOutbox(
				$db, $this->createMock(IJobList::class), $root,
				$this->createMock(IUserManager::class),
				$this->createMock(SubscriptionService::class),
				$mail, $this->createMock(LoggerInterface::class),
			);
			$outbox->deliverById($id);
			$q = $db->getQueryBuilder();
			$q->select('status', 'subject', 'body_text', 'body_html')
				->from('abonnieren_mail_outbox')
				->where($q->expr()->eq('id', $q->createNamedParameter($id)));
			$result = $q->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			self::assertSame('cancelled', $row['status']);
			self::assertSame('', $row['subject']);
			self::assertSame('', $row['body_text']);
			self::assertSame('', $row['body_html']);
		} finally {
			$q = $db->getQueryBuilder();
			$q->delete('abonnieren_mail_outbox')
				->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
			$q->executeStatement();
		}
	}

	public function testGroupedMailSendsOnlyStillAccessibleFiles(): void {
		$db = \OC::$server->get(IDBConnection::class);
		$uid = 'abo_ci_group_' . bin2hex(random_bytes(5));
		$key = bin2hex(random_bytes(32));
		$ids = [];
		try {
			foreach (['included.txt', 'excluded.txt'] as $i => $name) {
				$q = $db->getQueryBuilder();
				$q->insert('abonnieren_mail_outbox')->values([
					'user_id' => $q->createNamedParameter($uid),
					'node_id' => $q->createNamedParameter(966000 + $i),
					'event_mask' => $q->createNamedParameter(SubscriptionService::EVENT_UPLOAD),
					'actor_id' => $q->createNamedParameter('editor'),
					'deleted' => $q->createNamedParameter(0),
					'subject' => $q->createNamedParameter('Uploaded'),
					'body_text' => $q->createNamedParameter($name),
					'body_html' => $q->createNamedParameter('<p>' . $name . '</p>'),
					'status' => $q->createNamedParameter('pending'),
					'created_at' => $q->createNamedParameter(time() - 10),
					'updated_at' => $q->createNamedParameter(time() - 10),
					'next_attempt_at' => $q->createNamedParameter(time() - 5),
					'batch_key' => $q->createNamedParameter($key),
					'batch_until' => $q->createNamedParameter(time() - 5),
					'item_name' => $q->createNamedParameter($name),
				]);
				$q->executeStatement();
				$ids[] = $db->lastInsertId('abonnieren_mail_outbox');
			}
			$node = $this->createMock(File::class);
			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturnCallback(
				static fn (int $id): array => $id === 966000 ? [$node] : [],
			);
			$root = $this->createMock(IRootFolder::class);
			$root->method('getUserFolder')->with($uid)->willReturn($folder);
			$user = $this->createMock(IUser::class);
			$user->method('getEMailAddress')->willReturn('example@example.test');
			$users = $this->createMock(IUserManager::class);
			$users->method('get')->willReturn($user);
			$subs = $this->createMock(SubscriptionService::class);
			$subs->method('getRecipientEmailsForNode')->willReturn([$uid => 'example@example.test']);
			$message = $this->createMock(IMessage::class);
			$message->expects($this->once())->method('setPlainBody')->with('included.txt');
			$mail = $this->createMock(IMailer::class);
			$mail->method('createMessage')->willReturn($message);
			$mail->expects($this->once())->method('send')->willReturn([]);
			$outbox = new MailOutbox(
				$db, $this->createMock(IJobList::class), $root, $users, $subs, $mail,
				$this->createMock(LoggerInterface::class),
			);
			$outbox->deliverUploadBatchById($ids[0]);
			$outbox->deliverUploadBatchById($ids[1]);
			$q = $db->getQueryBuilder();
			$q->select('id', 'status', 'body_text')->from('abonnieren_mail_outbox')
				->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
			$result = $q->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			$byId = [];
			foreach ($rows as $row) {
				$byId[(int)$row['id']] = $row;
			}
			self::assertSame('sent', $byId[$ids[0]]['status']);
			self::assertSame('cancelled', $byId[$ids[1]]['status']);
			self::assertSame('', $byId[$ids[1]]['body_text']);
		} finally {
			$q = $db->getQueryBuilder();
			$q->delete('abonnieren_mail_outbox')
				->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
			$q->executeStatement();
		}
	}

}
