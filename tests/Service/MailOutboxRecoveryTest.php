<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCA\Abonnieren\BackgroundJob\ReconcileOutboxJob;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\MailRetryPolicy;
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

class MailOutboxRecoveryTest extends TestCase {
	private function query(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('delete')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':param');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x = :param');
		$expr->method('lte')->willReturn('x <= :param');
		$expr->method('lt')->willReturn('x < :param');
		$expr->method('gt')->willReturn('x > :param');
		$expr->method('in')->willReturn('x IN (:param)');
		$qb->method('expr')->willReturn($expr);
		return $qb;
	}

	private function withRows(IQueryBuilder $qb, array $rows): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($result);
	}

	private function service(IDBConnection $db, IJobList $jobs): MailOutbox {
		return new MailOutbox($db, $jobs,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(SubscriptionService::class),
			$this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class));
	}

	public function testReconcilerReschedulesOrphanedPendingRows(): void {
		$expired = $this->query();
		$this->withRows($expired, []);
		$due = $this->query();
		$this->withRows($due, [['id' => 99]]);
		$cleanup = $this->query();
		$cleanup->expects($this->once())->method('executeStatement');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($expired, $due, $cleanup);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')
			->with(DeliverMailJob::class, self::callback(static fn ($when): bool => is_int($when) && $when <= time()), ['id' => 99]);
		$this->service($db, $jobs)->reconcile();
	}

	public function testExpiredLeaseIsRetriedWithBackoff(): void {
		$expired = $this->query();
		$this->withRows($expired, [['id' => 88, 'attempts' => 2]]);
		$recover = $this->query();
		$recover->expects($this->once())->method('executeStatement')->willReturn(1);
		$due = $this->query();
		$this->withRows($due, []);
		$cleanup = $this->query();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($expired, $recover, $due, $cleanup);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')
			->with(DeliverMailJob::class, self::callback(static fn ($when): bool =>
				is_int($when) && $when >= time() + MailRetryPolicy::delayForAttempt(2) - 3
			), ['id' => 88]);
		$this->service($db, $jobs)->reconcile();
	}

	public function testExhaustedLeaseBecomesTerminalFailure(): void {
		$expired = $this->query();
		$this->withRows($expired, [['id' => 77, 'attempts' => MailRetryPolicy::MAX_ATTEMPTS]]);
		$expire = $this->query();
		$expire->expects($this->once())->method('executeStatement')->willReturn(1);
		$due = $this->query();
		$this->withRows($due, []);
		$cleanup = $this->query();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($expired, $expire, $due, $cleanup);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');
		$this->service($db, $jobs)->reconcile();
	}

	public function testTimedJobDelegatesToReconciliation(): void {
		$outbox = $this->createMock(MailOutbox::class);
		$outbox->expects($this->once())->method('reconcile');
		$job = new ReconcileOutboxJob($this->createMock(ITimeFactory::class), $outbox);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
