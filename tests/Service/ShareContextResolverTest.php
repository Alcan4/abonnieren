<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\ShareContextResolver;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareContextResolverTest extends TestCase {
	private function resolver(IRootFolder $rootFolder): ShareContextResolver {
		return new ShareContextResolver(
			$this->createMock(IRequest::class),
			$this->createMock(IManager::class),
			$rootFolder,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testMissingShareReturnsOriginalNode(): void {
		$node = $this->createMock(Node::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		self::assertSame($node, $this->resolver($rootFolder)->resolveOwnerNode($node, null));
	}

	public function testEmptyShareOwnerPreservesOriginalNodeWithoutUserLookup(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$shareNode = $this->createMock(Node::class);
		$shareNode->method('getId')->willReturn(7);
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($shareNode);
		$share->method('getSharedBy')->willReturn('');
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		self::assertSame($node, $this->resolver($rootFolder)->resolveOwnerNode($node, $share));
	}

	public function testSameFileIdReturnsShareNodeWithoutOwnerLookup(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$shareNode = $this->createMock(Node::class);
		$shareNode->method('getId')->willReturn(42);
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($shareNode);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		self::assertSame($shareNode, $this->resolver($rootFolder)->resolveOwnerNode($node, $share));
	}

	public function testValidShareOwnerResolvesNodeFromOwnersFolder(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$shareNode = $this->createMock(Node::class);
		$shareNode->method('getId')->willReturn(7);
		$resolvedNode = $this->createMock(Node::class);
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($shareNode);
		$share->method('getSharedBy')->willReturn('owner');
		$ownerFolder = $this->createMock(Folder::class);
		$ownerFolder->expects($this->once())->method('getById')->with(42)->willReturn([$resolvedNode]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())->method('getUserFolder')->with('owner')->willReturn($ownerFolder);

		self::assertSame($resolvedNode, $this->resolver($rootFolder)->resolveOwnerNode($node, $share));
	}
}
