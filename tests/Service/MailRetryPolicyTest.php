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
		self::assertSame(960, MailRetryPolicy::delayForAttempt(5));
		self::assertSame(1920, MailRetryPolicy::delayForAttempt(6));
		self::assertSame(3600, MailRetryPolicy::delayForAttempt(7));
		self::assertSame(3600, MailRetryPolicy::delayForAttempt(20));
	}

	public function testRetryBudgetSurvivesAnHourLongOutage(): void {
		self::assertFalse(MailRetryPolicy::exhausted(1));
		self::assertFalse(MailRetryPolicy::exhausted(7));
		self::assertFalse(MailRetryPolicy::exhausted(MailRetryPolicy::MAX_ATTEMPTS - 1));
		self::assertTrue(MailRetryPolicy::exhausted(MailRetryPolicy::MAX_ATTEMPTS));
		self::assertTrue(MailRetryPolicy::exhausted(MailRetryPolicy::MAX_ATTEMPTS + 1));
	}
}
