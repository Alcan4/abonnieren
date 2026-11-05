<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\MailRetryPolicy;
use OCA\Abonnieren\Service\SubscriptionService;
use OCA\Abonnieren\Service\SummaryMailBuilder;
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
	private function withQueuedMessage(array $row, int $claimed = 1): array {
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('executeStatement')->willReturn($claimed);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('field = :param');
		$expr->method('lte')->willReturn('field <= :param');
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$qb->method('executeQuery')->willReturn($result);
		return [$db, $qb];
	}

	private function row(): array {
		return [
			'status' => 'sending', 'attempts' => 1,
			'user_id' => 'subscriber', 'node_id' => 42, 'parent_id' => null,
			'deleted' => 0, 'event_mask' => SubscriptionService::EVENT_UPLOAD,
			'actor_id' => 'uploader', 'subject' => 'New file',
			'body_text' => 'Hello', 'body_html' => '<p>Hello</p>',
		];
	}

	public function testAlreadyClaimedRowCannotSendAgain(): void {
		[$db, $qb] = $this->withQueuedMessage($this->row(), 0);
		$qb->expects($this->never())->method('executeQuery');
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		(new MailOutbox($db, $this->createMock(IJobList::class),
			$this->createMock(IRootFolder::class), $this->createMock(IUserManager::class),
			$this->createMock(SubscriptionService::class), $mailer, $this->createMock(LoggerInterface::class),
			$this->createMock(SummaryMailBuilder::class)))
			->deliverById(17);
	}

	public function testRevokedFileAccessCancelsQueuedMail(): void {
		[$db, $qb] = $this->withQueuedMessage($this->row());
		$qb->expects($this->exactly(2))->method('executeStatement');
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
			$this->createMock(LoggerInterface::class), $this->createMock(SummaryMailBuilder::class)))
			->deliverById(17);
	}

	/** @return array{IRootFolder,SubscriptionService,IUserManager} */
	private function authorizedRecipient(): array {
		$node = $this->createMock(Node::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn([$node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('subscriber')->willReturn($folder);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')
			->with($node, SubscriptionService::EVENT_UPLOAD, 'uploader')
			->willReturn(['subscriber' => 'recipient@example.test']);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('recipient@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('subscriber')->willReturn($user);
		return [$root, $subscriptions, $users];
	}

	public function testCurrentSubscriberReceivesQueuedMail(): void {
		[$db, $qb] = $this->withQueuedMessage($this->row());
		$qb->expects($this->exactly(2))->method('executeStatement');
		[$root, $subscriptions, $users] = $this->authorizedRecipient();
		$mailer = $this->createMock(IMailer::class);
		$message = $this->createMock(IMessage::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message)->willReturn([]);
		(new MailOutbox($db, $this->createMock(IJobList::class), $root,
			$users, $subscriptions, $mailer, $this->createMock(LoggerInterface::class),
			$this->createMock(SummaryMailBuilder::class)))
			->deliverById(17);
	}

	public function testSecondRecipientPathCanAuthorizeQueuedMail(): void {
		[$db, $qb] = $this->withQueuedMessage($this->row());
		$qb->expects($this->exactly(2))->method('executeStatement');
		$first = $this->createMock(Node::class);
		$second = $this->createMock(Node::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn([$first, $second]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('subscriber')->willReturn($folder);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->expects($this->exactly(2))->method('getRecipientEmailsForNode')
			->willReturnCallback(static fn (Node $node): array => $node === $second
				? ['subscriber' => 'recipient@example.test']
				: []);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('recipient@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send')->willReturn([]);
		(new MailOutbox($db, $this->createMock(IJobList::class), $root, $users,
			$subscriptions, $mailer, $this->createMock(LoggerInterface::class),
			$this->createMock(SummaryMailBuilder::class)))->deliverById(17);
	}

	public function testFailedSendSchedulesRetryWithoutBlockingOthers(): void {
		[$db, $qb] = $this->withQueuedMessage($this->row());
		$qb->expects($this->exactly(2))->method('executeStatement');
		[$root, $subscriptions, $users] = $this->authorizedRecipient();
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send')
			->willThrowException(new \RuntimeException('Simulated SMTP outage'));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')
			->with(\OCA\Abonnieren\BackgroundJob\DeliverMailJob::class,
				self::callback(static fn ($when): bool => is_int($when) && $when >= time()),
				['id' => 17]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		(new MailOutbox($db, $jobs, $root, $users, $subscriptions, $mailer, $logger,
			$this->createMock(SummaryMailBuilder::class)))
			->deliverById(17);
	}

	public function testFinalFailureDoesNotScheduleFurtherRetry(): void {
		$row = $this->row();
		$row['attempts'] = MailRetryPolicy::MAX_ATTEMPTS;
		[$db, $qb] = $this->withQueuedMessage($row);
		$qb->expects($this->exactly(2))->method('executeStatement');
		[$root, $subscriptions, $users] = $this->authorizedRecipient();
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('send')->willThrowException(new \RuntimeException('Simulated outage'));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');
		(new MailOutbox($db, $jobs, $root, $users, $subscriptions, $mailer,
			$this->createMock(LoggerInterface::class), $this->createMock(SummaryMailBuilder::class)))
			->deliverById(17);
	}
}
