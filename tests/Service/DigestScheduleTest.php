<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\DigestSchedule;
use PHPUnit\Framework\TestCase;

class DigestScheduleTest extends TestCase {
	private function at(string $date, string $timezone = 'Europe/Berlin'): int {
		return (new \DateTimeImmutable($date, new \DateTimeZone($timezone)))->getTimestamp();
	}

	public function testInstantModeRetainsImmediateDelivery(): void {
		$now = $this->at('2026-10-30 12:10:00');
		self::assertSame($now, DigestSchedule::dueAt($now, 'instant', '08:00', 'Europe/Berlin'));
	}

	public function testHourlyModeMovesToNextHour(): void {
		self::assertSame(
			$this->at('2026-10-30 13:00:00'),
			DigestSchedule::dueAt($this->at('2026-10-30 12:10:30'), 'hourly', '08:00', 'Europe/Berlin'),
		);
	}

	public function testDailyModeWaitsForNextLocalDigestTime(): void {
		self::assertSame(
			$this->at('2026-10-30 18:30:00'),
			DigestSchedule::dueAt($this->at('2026-10-30 10:00:00'), 'daily', '18:30', 'Europe/Berlin'),
		);
		self::assertSame(
			$this->at('2026-10-31 08:00:00'),
			DigestSchedule::dueAt($this->at('2026-10-30 09:00:00'), 'daily', '08:00', 'Europe/Berlin'),
		);
	}

	public function testQuietHoursPushHourlyDigestToMorning(): void {
		self::assertSame(
			$this->at('2026-10-31 07:00:00'),
			DigestSchedule::dueAt(
				$this->at('2026-10-30 22:10:00'), 'hourly', '08:00', 'Europe/Berlin', '22:00', '07:00',
			),
		);
	}

	public function testDigestKeyIsStablePerRecipientAndSchedule(): void {
		$key = DigestSchedule::key('alice', 1234567890, '22:00', '07:00', 'Europe/Berlin');
		self::assertSame($key, DigestSchedule::key('alice', 1234567890, '22:00', '07:00', 'Europe/Berlin'));
		self::assertNotSame($key, DigestSchedule::key('bob', 1234567890, '22:00', '07:00', 'Europe/Berlin'));
		self::assertNotSame($key, DigestSchedule::key('alice', 1234567891, '22:00', '07:00', 'Europe/Berlin'));
		self::assertNotSame($key, DigestSchedule::key('alice', 1234567890, '', '', 'Europe/Berlin'));
	}

	public function testDigestSummaryEscapesNamesAndSubjects(): void {
		$summary = DigestSchedule::message([
			['subject' => 'File uploaded', 'item_name' => 'report.pdf'],
			['subject' => 'File <modified>', 'item_name' => '<script>alert(1)</script>'],
		]);
		self::assertSame('2 subscription events', $summary['subject']);
		self::assertStringContainsString('- File uploaded: report.pdf', $summary['text']);
		self::assertStringContainsString('&lt;script&gt;', $summary['html']);
		self::assertStringNotContainsString('<script>', $summary['html']);
	}

	public function testRejectsUnsupportedDigestMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		DigestSchedule::validate('weekly', '08:00');
	}

	public function testRejectsBadDigestTime(): void {
		$this->expectException(\InvalidArgumentException::class);
		DigestSchedule::validate('daily', '25:00');
	}
}
