<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

class RecipientVisiblePathTest extends TestCase {
	public function testSharedMountUsesRecipientPathNotOwnerPrivateDirectories(): void {
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(42);
		$source->expects($this->never())->method('getPath');

		$visible = $this->createMock(File::class);
		$visible->method('getPath')->willReturn('/bob/files/Shared/report.pdf');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())->method('getById')->with(42)->willReturn([$visible]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('bob')->willReturn($userFolder);

		self::assertSame('/Shared/report.pdf', (new RecipientVisiblePath($root))->pathFor('bob', $source));
	}

	public function testInaccessibleFileDoesNotFallBackToOwnerPrivatePath(): void {
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(42);
		$source->expects($this->never())->method('getPath');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		self::assertNull((new RecipientVisiblePath($root))->pathFor('bob', $source));
	}
}
