<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\SubscriptionPreferences;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class SubscriptionPreferencesTest extends TestCase {
	public function testNormalizesCaseDotsWildcardAndDuplicates(): void {
		self::assertSame(['tmp', 'log', 'tar.gz'],
			SubscriptionPreferences::parseExtensions(' .TMP, *.log, tar.gz, log, '));
		self::assertSame('tmp,log,tar.gz',
			SubscriptionPreferences::normalizeExtensions('.TMP,*.log, tar.gz'));
	}

	public function testExcludesFilesByCaseInsensitiveSuffixIncludingCompoundExtensions(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('archive.TAR.GZ');
		self::assertTrue(SubscriptionPreferences::isExcluded($file, ['tar.gz']));
		self::assertFalse(SubscriptionPreferences::isExcluded($file, ['zip']));
	}

	public function testDoesNotExcludeFoldersEvenIfTheyHaveDots(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn('archive.tmp');
		self::assertFalse(SubscriptionPreferences::isExcluded($folder, ['tmp']));
	}

	public function testZeroDebounceIsAllowedAndLegacyDefaultIsPreserved(): void {
		self::assertSame(0, SubscriptionPreferences::validateDebounce(0));
		self::assertSame(600, SubscriptionPreferences::DEFAULT_DEBOUNCE_SECONDS);
		self::assertSame(3600, SubscriptionPreferences::validateDebounce(3600));
	}

	public function testRejectsOutOfRangeSuppressionWindow(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionPreferences::validateDebounce(3601);
	}

	public function testRejectsPathPatternsInsteadOfExtensions(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionPreferences::parseExtensions('../private,tmp');
	}

	public function testRejectsOverlyLargeLists(): void {
		$this->expectException(\InvalidArgumentException::class);
		SubscriptionPreferences::parseExtensions(implode(',', array_map(
			static fn ($i): string => 'ext' . $i, range(1, 31),
		)));
	}

	public function testEmptyExclusionListMatchesNothing(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('readme');
		self::assertSame([], SubscriptionPreferences::parseExtensions(' , , '));
		self::assertFalse(SubscriptionPreferences::isExcluded($file, []));
	}
}
