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
}
