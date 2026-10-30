<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailOutboxDeliveryTest extends TestCase {
	/** @return array{IDBConnection,IQueryBuilder} */
	private function withQueuedMessage(array $row): array {
		$db = $this->createMock(IDBConnection::class);
		$select = $this->createMock(IQueryBuilder::class);
		$delete = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, $delete);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');
		foreach ([$select, $delete] as $query) {
			$query->method('expr')->willReturn($expr);
			$query->method('createNamedParameter')->willReturn(':id');
			$query->method('where')->willReturnSelf();
		}
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$select->method('executeQuery')->willReturn($result);
		$delete->method('delete')->willReturnSelf();
		$delete->expects($this->once())->method('executeStatement');
		return [$db, $delete];
	}

	private function row(): array {
		return [
			'status' => 'pending', 'user_id' => 'subscriber', 'node_id' => 42,
			'parent_id' => null, 'deleted' => 0, 'event_mask' => SubscriptionService::EVENT_UPLOAD,
			'actor_id' => 'uploader', 'subject' => 'New file',
			'body_text' => 'Hello', 'body_html' => '<p>Hello</p>',
		];
	}

	public function testRevokedFileAccessPreventsQueuedMail(): void {
		[$db] = $this->withQueuedMessage($this->row());
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('getById')->with(42)->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('subscriber')->willReturn($folder);
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->expects($this->never())->method('getRecipientEmailsForNode');

		(new MailOutbox($db, $this->createMock(IJobList::class), $root,
			$this->createMock(IUserManager::class), $subscriptions, $mailer,
			$this->createMock(LoggerInterface::class)))->deliverById(17);
	}

	public function testCurrentSubscriberReceivesQueuedMail(): void {
		[$db] = $this->withQueuedMessage($this->row());
		$node = $this->createMock(Node::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn([$node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('subscriber')->willReturn($folder);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')->with($node,
			SubscriptionService::EVENT_UPLOAD, 'uploader')
			->willReturn(['subscriber' => 'recipient@example.test']);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('recipient@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('subscriber')->willReturn($user);
		$mailer = $this->createMock(IMailer::class);
		$message = $this->createMock(IMessage::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		(new MailOutbox($db, $this->createMock(IJobList::class), $root,
			$users, $subscriptions, $mailer, $this->createMock(LoggerInterface::class)))
			->deliverById(17);
	}
}
