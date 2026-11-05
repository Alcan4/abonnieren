<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\SubscriptionLifecycle;
use PHPUnit\Framework\TestCase;

class SubscriptionLifecycleTest extends TestCase {
	public function testPermanentSubscriptionAndNoSnoozeRemainEnabled(): void {
		self::assertSame(['expiresAt' => 0, 'snoozedUntil' => 0],
			SubscriptionLifecycle::validate(0, 0, 1000));
		self::assertFalse(SubscriptionLifecycle::expired(0, 1000));
		self::assertFalse(SubscriptionLifecycle::snoozed(0, 1000));
	}

	public function testExpirationBoundaryIsInclusive(): void {
		self::assertFalse(SubscriptionLifecycle::expired(1001, 1000));
		self::assertTrue(SubscriptionLifecycle::expired(1000, 1000));
		self::assertTrue(SubscriptionLifecycle::expired(999, 1000));
	}

	public function testSnoozeEndsPreciselyAtRequestedTime(): void {
		self::assertTrue(SubscriptionLifecycle::snoozed(1001, 1000));
		self::assertFalse(SubscriptionLifecycle::snoozed(1000, 1000));
		self::assertFalse(SubscriptionLifecycle::snoozed(999, 1000));
	}

	public function testExpiredSnoozeIsClearedOnSave(): void {
		self::assertSame(['expiresAt' => 0, 'snoozedUntil' => 0],
			SubscriptionLifecycle::validate(0, 999, 1000));
	}

	public function testAcceptsTemporarySubscriptionWithinOneYear(): void {
		self::assertSame(1000 + 30 * 86400,
			SubscriptionLifecycle::validate(1000 + 30 * 86400, 0, 1000)['expiresAt']);
	}

	public function testRejectsPastSubscriptionExpiration(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionLifecycle::validate(999, 0, 1000);
	}

	public function testRejectsMoreThanOneYearExpiration(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionLifecycle::validate(1000 + SubscriptionLifecycle::MAX_EXPIRATION_SECONDS + 1, 0, 1000);
	}

	public function testRejectsOverlyLongSnooze(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionLifecycle::validate(0, 1000 + SubscriptionLifecycle::MAX_SNOOZE_SECONDS + 1, 1000);
	}
}
