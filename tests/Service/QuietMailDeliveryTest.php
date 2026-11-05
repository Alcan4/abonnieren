<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCA\Abonnieren\Service\SummaryMailBuilder;
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

class QuietMailDeliveryTest extends TestCase {
	public function testWorkerDefersIfQuietPeriodBeginsBeforeExecution(): void {
		$now = time();
		$start = gmdate('H:i', $now - 60);
		$end = gmdate('H:i', $now + 180);

		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$qb->method('update')->willReturnSelf();
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->expects($this->exactly(2))->method('executeStatement')->willReturn(1);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x = :p');
		$expr->method('lte')->willReturn('x <= :p');
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'batch_key' => '', 'attempts' => 1,
			'quiet_start' => $start, 'quiet_end' => $end, 'quiet_timezone' => 'UTC',
		]);
		$qb->method('executeQuery')->willReturn($result);

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')
			->with(DeliverMailJob::class,
				self::callback(static fn ($when): bool => is_int($when) && $when > time()),
				['id' => 31]);

		$outbox = new MailOutbox(
			$db, $jobs, $this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(SubscriptionService::class),
			$mailer, $this->createMock(LoggerInterface::class), $this->createMock(SummaryMailBuilder::class),
		);
		$outbox->deliverById(31);
	}
}
