<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
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

class UploadBatchWorkerTest extends TestCase {
	public function testPreviouslyScheduledJobCannotSendBeforeResetDeadline(): void {
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
			'status' => 'pending',
			'batch_key' => 'a-batch',
			'batch_until' => time() + 90,
			'next_attempt_at' => time() + 90,
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

		$outbox->deliverUploadBatchById(42);
	}
}
