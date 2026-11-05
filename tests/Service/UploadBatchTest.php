<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\UploadBatch;
use PHPUnit\Framework\TestCase;

class UploadBatchTest extends TestCase {
	public function testNewUploadResetsNinetySecondQuietWindow(): void {
		$start = 1000;
		self::assertSame(1090, UploadBatch::dueAt($start, 1000));
		self::assertTrue(UploadBatch::canAppend(1040, $start, 1000, 1));
		self::assertSame(1130, UploadBatch::dueAt($start, 1040));
		self::assertTrue(UploadBatch::canAppend(1120, $start, 1040, 2));
		self::assertSame(1210, UploadBatch::dueAt($start, 1120));
		self::assertFalse(UploadBatch::canAppend(1210, $start, 1120, 3));
	}

	public function testContinuousUploadsCannotDelayBeyondTenMinutes(): void {
		$start = 1000;
		self::assertSame(1600, UploadBatch::dueAt($start, 1590));
		self::assertTrue(UploadBatch::canAppend(1599, $start, 1590, 10));
		self::assertFalse(UploadBatch::canAppend(1600, $start, 1590, 10));
		self::assertSame(1600, UploadBatch::dueAt($start, 1600));
	}

	public function testFiftyItemsClosesBatchEvenDuringActivity(): void {
		self::assertTrue(UploadBatch::canAppend(1040, 1000, 1030, 49));
		self::assertFalse(UploadBatch::canAppend(1040, 1000, 1030, 50));
	}

	public function testScopesSeparateRecipientActorAndFolder(): void {
		$one = UploadBatch::scopeKey('alice', 'uploader', 10);
		self::assertSame($one, UploadBatch::scopeKey('alice', 'uploader', 10));
		self::assertNotSame($one, UploadBatch::scopeKey('bob', 'uploader', 10));
		self::assertNotSame($one, UploadBatch::scopeKey('alice', 'another', 10));
		self::assertNotSame($one, UploadBatch::scopeKey('alice', 'uploader', 11));
	}

}
