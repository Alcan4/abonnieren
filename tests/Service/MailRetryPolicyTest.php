<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\MailRetryPolicy;
use PHPUnit\Framework\TestCase;

class MailRetryPolicyTest extends TestCase {
	public function testBackoffIsExponentialAndBounded(): void {
		self::assertSame(60, MailRetryPolicy::delayForAttempt(1));
		self::assertSame(120, MailRetryPolicy::delayForAttempt(2));
		self::assertSame(240, MailRetryPolicy::delayForAttempt(3));
		self::assertSame(480, MailRetryPolicy::delayForAttempt(4));
		self::assertSame(3600, MailRetryPolicy::delayForAttempt(20));
	}

	public function testFiveAttemptsExhaustRetryBudget(): void {
		self::assertFalse(MailRetryPolicy::exhausted(1));
		self::assertFalse(MailRetryPolicy::exhausted(4));
		self::assertTrue(MailRetryPolicy::exhausted(5));
		self::assertTrue(MailRetryPolicy::exhausted(6));
	}
}
