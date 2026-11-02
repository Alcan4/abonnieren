<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\FolderExclusions;
use PHPUnit\Framework\TestCase;

class FolderExclusionsTest extends TestCase {
	public function testNormalizesAndDeduplicatesRelativePaths(): void {
		self::assertSame(['Archive', 'Projects/Drafts'],
			FolderExclusions::parse(' Archive, Projects/Drafts, Archive, '));
		self::assertSame('Archive,Projects/Drafts',
			FolderExclusions::normalize(' Archive , Projects/Drafts '));
	}

	public function testExcludedFolderAndNestedDescendantsMatch(): void {
		self::assertTrue(FolderExclusions::matches(['Archive'], ['Archive'], false));
		self::assertTrue(FolderExclusions::matches(['Projects/Drafts'], ['Projects', 'Drafts', 'notes.pdf'], true));
		self::assertTrue(FolderExclusions::matches(['Archive'], ['Archive', 'nested', 'report.pdf'], true));
	}

	public function testFolderNamesAreMatchedByExactSegmentsAndCase(): void {
		self::assertFalse(FolderExclusions::matches(['Archive'], ['Archive2', 'a.pdf'], true));
		self::assertFalse(FolderExclusions::matches(['Archive'], ['archive', 'a.pdf'], true));
		self::assertFalse(FolderExclusions::matches(['Projects/Drafts'], ['Projects', 'Drafts2', 'a.pdf'], true));
	}

	public function testFileNamedLikeExcludedFolderIsNotExcluded(): void {
		self::assertFalse(FolderExclusions::matches(['Archive'], ['Archive'], true));
		self::assertTrue(FolderExclusions::matches(['Archive'], ['Archive', 'report.pdf'], true));
		self::assertFalse(FolderExclusions::matches(['Archive'], [], false));
	}

	public function testRejectsParentTraversal(): void {
		$this->expectException(\InvalidArgumentException::class);
		FolderExclusions::parse('Projects/../Secrets');
	}

	public function testRejectsLeadingAbsolutePath(): void {
		$this->expectException(\InvalidArgumentException::class);
		FolderExclusions::parse('/Shared/Secret');
	}

	public function testRejectsDoubleSlashes(): void {
		$this->expectException(\InvalidArgumentException::class);
		FolderExclusions::parse('Projects//Drafts');
	}

	public function testRejectsBackslashes(): void {
		$this->expectException(\InvalidArgumentException::class);
		FolderExclusions::parse('Projects\\Drafts');
	}

	public function testRejectsExcessiveNumberOfPaths(): void {
		$this->expectException(\InvalidArgumentException::class);
		FolderExclusions::parse(implode(',', array_map(static fn ($i): string => 'dir' . $i, range(1, 31))));
	}

	public function testRootSubscriptionItselfIsNeverExcluded(): void {
		self::assertFalse(FolderExclusions::matches(['Archive'], []));
	}
}
