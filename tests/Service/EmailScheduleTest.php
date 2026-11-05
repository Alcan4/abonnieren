<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\EmailSchedule;
use PHPUnit\Framework\TestCase;

class EmailScheduleTest extends TestCase {
	private function at(string $when, string $timezone = 'Europe/Berlin'): int {
		return (new \DateTimeImmutable($when, new \DateTimeZone($timezone)))->getTimestamp();
	}

	public function testDisabledQuietHoursPreserveImmediateDelivery(): void {
		$now = $this->at('2026-10-30 23:00:00');
		self::assertSame($now, EmailSchedule::dueAt($now, '', '', 'Europe/Berlin'));
	}

	public function testOvernightQuietHoursWaitUntilFollowingMorning(): void {
		self::assertSame(
			$this->at('2026-10-31 07:00:00'),
			EmailSchedule::dueAt($this->at('2026-10-30 23:15:00'), '22:00', '07:00', 'Europe/Berlin'),
		);
		self::assertSame(
			$this->at('2026-10-30 07:00:00'),
			EmailSchedule::dueAt($this->at('2026-10-30 06:30:00'), '22:00', '07:00', 'Europe/Berlin'),
		);
	}

	public function testOutsideQuietHoursDoesNotDelayMail(): void {
		$now = $this->at('2026-10-30 12:00:00');
		self::assertSame($now, EmailSchedule::dueAt($now, '22:00', '07:00', 'Europe/Berlin'));
	}

	public function testSameDayQuietHoursAreSupported(): void {
		self::assertSame(
			$this->at('2026-10-30 15:30:00'),
			EmailSchedule::dueAt($this->at('2026-10-30 13:15:00'), '12:00', '15:30', 'Europe/Berlin'),
		);
	}

	public function testQuietHoursHandleAutumnDstChange(): void {
		self::assertSame(
			$this->at('2026-10-25 07:00:00'),
			EmailSchedule::dueAt($this->at('2026-10-24 23:30:00'), '22:00', '07:00', 'Europe/Berlin'),
		);
	}

	public function testRejectsMissingEndTime(): void {
		$this->expectException(\InvalidArgumentException::class);
		EmailSchedule::validate('22:00', '', 'Europe/Berlin');
	}

	public function testRejectsIdenticalStartAndEndTime(): void {
		$this->expectException(\InvalidArgumentException::class);
		EmailSchedule::validate('08:00', '08:00', 'UTC');
	}

	public function testRejectsUnknownTimezone(): void {
		$this->expectException(\InvalidArgumentException::class);
		EmailSchedule::validate('22:00', '07:00', 'Mars/OlympusMons');
	}

	public function testRejectsMalformedTimes(): void {
		$this->expectException(\InvalidArgumentException::class);
		EmailSchedule::validate('25:30', '07:00', 'UTC');
	}
}
