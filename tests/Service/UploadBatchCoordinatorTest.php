<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\UploadBatchCoordinator;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class UploadBatchCoordinatorTest extends TestCase {
	/** @return array{IDBConnection,IQueryBuilder} */
	private function setupScope(array $scope): array {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->expects($this->never())->method('rollBack');
		$db->expects($this->once())->method('insertIfNotExist');

		$qb = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('forUpdate')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':value');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('column = :value');
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($scope);
		$qb->method('executeQuery')->willReturn($result);
		return [$db, $qb];
	}

	public function testAppendUpdatesDeadlineOfPreviousPendingItems(): void {
		[$db, $qb] = $this->setupScope([
			'batch_key' => 'existing-batch',
			'started_at' => 1000,
			'last_at' => 1040,
			'due_at' => 1130,
			'item_count' => 2,
		]);
		$qb->expects($this->exactly(2))->method('executeStatement');
		$seen = [];
		$qb->method('set')->willReturnCallback(static function (string $name, mixed $value) use (&$seen, $qb) {
			$seen[] = $name;
			return $qb;
		});
		$result = (new UploadBatchCoordinator($db))->add(
			'alice', 'uploader', 22, 1080,
			static function (string $key, int $due): int {
				self::assertSame('existing-batch', $key);
				self::assertSame(1170, $due);
				return 44;
			},
		);
		self::assertSame(['id' => 44, 'due' => 1170], $result);
		self::assertContains('batch_until', $seen);
		self::assertContains('next_attempt_at', $seen);
	}

	public function testExpiredWindowStartsSeparateBatch(): void {
		[$db, $qb] = $this->setupScope([
			'batch_key' => 'previous-batch',
			'started_at' => 1000,
			'last_at' => 1040,
			'due_at' => 1130,
			'item_count' => 3,
		]);
		$qb->expects($this->once())->method('executeStatement');
		$result = (new UploadBatchCoordinator($db))->add(
			'alice', 'uploader', 22, 1130,
			static function (string $key, int $due): int {
				self::assertNotSame('previous-batch', $key);
				self::assertSame(1220, $due);
				return 45;
			},
		);
		self::assertSame(['id' => 45, 'due' => 1220], $result);
	}
}
