<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\BackgroundJob\DeliverDigestJob;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DigestDeliveryTest extends TestCase {
	public function testEarlyDigestJobCannotSend(): void {
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($qb);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':id');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'status' => 'pending', 'batch_key' => '', 'digest_key' => 'opaque-digest',
			'next_attempt_at' => time() + 3600,
		]);
		$qb->expects($this->once())->method('executeQuery')->willReturn($result);
		$qb->expects($this->never())->method('executeStatement');
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$outbox = new MailOutbox(
			$db, $this->createMock(IJobList::class), $this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class), $this->createMock(SubscriptionService::class),
			$mailer, $this->createMock(LoggerInterface::class),
		);
		$outbox->deliverDigestById(42);
	}

	public function testDigestJobDelegatesOnlyValidRowId(): void {
		$outbox = $this->createMock(MailOutbox::class);
		$outbox->expects($this->once())->method('deliverDigestById')->with(44);
		$job = new DeliverDigestJob($this->createMock(ITimeFactory::class), $outbox);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, ['id' => 44]);
		$run->invoke($job, []);
	}
}
