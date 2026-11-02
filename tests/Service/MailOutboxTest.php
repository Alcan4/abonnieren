<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailOutboxTest extends TestCase {
	public function testEnqueuePersistsMessageAndSchedulesIdOnlyJobWithoutSending(): void {
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->expects($this->once())->method('executeStatement');
		$db->method('lastInsertId')->with('abonnieren_mail_outbox')->willReturn(123);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(DeliverMailJob::class, ['id' => 123]);
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$outbox = new MailOutbox($db, $jobs, $this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class), $this->createMock(SubscriptionService::class),
			$mailer, $this->createMock(LoggerInterface::class));

		$outbox->enqueue('subscriber', $node, SubscriptionService::EVENT_UPLOAD, 'uploader',
			false, 'File uploaded', 'Plain body', '<p>HTML body</p>');
	}

	public function testJobDeliversOnlyReferencedOutboxRow(): void {
		$outbox = $this->createMock(MailOutbox::class);
		$outbox->expects($this->once())->method('deliverById')->with(123);
		$job = new DeliverMailJob($this->createMock(ITimeFactory::class), $outbox);
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['id' => 123]);
	}

	public function testJobIgnoresInvalidArguments(): void {
		$outbox = $this->createMock(MailOutbox::class);
		$outbox->expects($this->never())->method('deliverById');
		$job = new DeliverMailJob($this->createMock(ITimeFactory::class), $outbox);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, []);
		$run->invoke($job, ['id' => 0]);
		$run->invoke($job, null);
	}
}
