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

		$resolver = new RecipientVisiblePath($root);
		self::assertSame('/Shared/report.pdf', $resolver->pathFor('bob', $source));
	}

	public function testDeletedNodePathUsesOnlyStillAccessibleRecipientParent(): void {
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(40);
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(42);
		$source->method('getName')->willReturn('deleted.txt');
		$source->method('getParent')->willReturn($parent);
		$source->expects($this->never())->method('getPath');

		$visibleParent = $this->createMock(Folder::class);
		$visibleParent->method('getPath')->willReturn('/bob/files/Shared');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())->method('getById')->with(40)->willReturn([$visibleParent]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('bob')->willReturn($userFolder);
		self::assertSame('/Shared/deleted.txt', (new RecipientVisiblePath($root))->pathFor('bob', $source, true));
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
