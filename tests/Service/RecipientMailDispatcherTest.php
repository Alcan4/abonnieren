<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\RecipientMailDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecipientMailDispatcherTest extends TestCase {
	public function testFailedRecipientDoesNotBlockLaterRecipients(): void {
		$attempted = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Could not send notification',
				self::callback(static fn (array $context): bool =>
					$context['app'] === 'abonnieren'
					&& $context['nodeId'] === 42
					&& $context['recipientUserId'] === 'bad'
					&& $context['exception'] instanceof \RuntimeException
				),
			);

		$dispatcher = new RecipientMailDispatcher($logger);
		$sent = $dispatcher->deliver(
			['bad' => 'bad@example.test', 'good' => 'good@example.test'],
			static function (string $userId, string $email) use (&$attempted): void {
				$attempted[] = [$userId, $email];
				if ($userId === 'bad') {
					throw new \RuntimeException('Simulated SMTP failure');
				}
			},
			'Could not send notification',
			['nodeId' => 42],
		);

		self::assertSame(['good'], $sent);
		self::assertSame(
			[['bad', 'bad@example.test'], ['good', 'good@example.test']],
			$attempted,
		);
	}

	public function testSuccessfulBatchReportsOnlySuccessfulRecipients(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$attempted = [];
		$sent = (new RecipientMailDispatcher($logger))->deliver(
			['first' => 'first@example.test', 'second' => 'second@example.test'],
			static function (string $userId, string $email) use (&$attempted): void {
				$attempted[] = $userId;
			},
			'Could not send notification',
		);

		self::assertSame(['first', 'second'], $sent);
		self::assertSame(['first', 'second'], $attempted);
	}

	public function testEachFailureIsLoggedAndFailedRecipientsAreNotReportedAsSent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error');
		$attempted = [];
		$sent = (new RecipientMailDispatcher($logger))->deliver(
			['first' => 'first@example.test', 'second' => 'second@example.test'],
			static function (string $userId, string $email) use (&$attempted): void {
				$attempted[] = $userId;
				throw new \RuntimeException('Simulated failure');
			},
			'Could not send notification',
		);

		self::assertSame([], $sent);
		self::assertSame(['first', 'second'], $attempted);
	}
}
