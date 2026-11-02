<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\SubscriptionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SubscriptionTransactionTest extends TestCase {
	public function testFailedReplacementInsertRollsBackDeletionOfPreviousRule(): void {
		$uid = 'test-user';
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('synthetic@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with($uid)->willReturn($user);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('column = :parameter');

		$delete = $this->createMock(IQueryBuilder::class);
		$delete->method('delete')->willReturnSelf();
		$delete->method('where')->willReturnSelf();
		$delete->method('andWhere')->willReturnSelf();
		$delete->method('createNamedParameter')->willReturn(':parameter');
		$delete->method('expr')->willReturn($expr);
		$delete->expects($this->once())->method('executeStatement')->willReturn(1);

		$insert = $this->createMock(IQueryBuilder::class);
		$insert->method('insert')->willReturnSelf();
		$insert->method('values')->willReturnSelf();
		$insert->method('createNamedParameter')->willReturn(':parameter');
		$insert->expects($this->once())->method('executeStatement')
			->willThrowException(new \RuntimeException('Synthetic database write failure'));

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($delete, $insert);

		$service = new SubscriptionService($db, $users, $this->createMock(IRootFolder::class));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Synthetic database write failure');
		$service->upsertRule($uid, 1234, SubscriptionService::EVENT_MODIFICATION, false);
	}
}
