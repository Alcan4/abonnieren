<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\MailOutbox;
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

class DigestGroupDeliveryTest extends TestCase {
	public function testTwoAuthorizedEventsProduceOneSummaryEmail(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn(':param');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x = :param');
		$expr->method('lte')->willReturn('x <= :param');
		$expr->method('in')->willReturn('x IN (:param)');
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->exactly(3))->method('executeStatement')->willReturn(1);

		$row = static fn (int $id, string $name): array => [
			'id' => $id, 'attempts' => 1, 'user_id' => 'subscriber',
			'node_id' => $id, 'event_mask' => SubscriptionService::EVENT_MODIFICATION,
			'actor_id' => 'editor', 'deleted' => 0,
			'subject' => 'File modified', 'item_name' => $name,
			'body_text' => $name, 'body_html' => '<p>File modified</p>',
		];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'status' => 'pending', 'digest_key' => 'digestgroup',
			'next_attempt_at' => time() - 1, 'quiet_start' => '',
			'quiet_end' => '', 'quiet_timezone' => 'UTC',
		]);
		$result->method('fetchAll')->willReturnOnConsecutiveCalls(
			[['id' => 11], ['id' => 12]],
			[$row(11, 'report.pdf'), $row(12, '<script>unsafe</script>')],
		);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->createMock(Node::class)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('subscriber')->willReturn($folder);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('getRecipientEmailsForNode')->willReturn(['subscriber' => 'test@example.test']);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('test@example.test');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setSubject')->with('2 subscription events');
		$message->expects($this->once())->method('setPlainBody')
			->with(self::stringContains('report.pdf'));
		$message->expects($this->once())->method('setHtmlBody')
			->with(self::stringContains('&lt;script&gt;'));
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$summary = $this->createMock(SummaryMailBuilder::class);
		$summary->expects($this->once())->method('build')->willReturn([
			'subject' => '2 subscription events',
			'text' => 'report.pdf',
			'html' => '&lt;script&gt;',
		]);
		$outbox = new MailOutbox($db, $this->createMock(IJobList::class), $root,
			$users, $subscriptions, $mailer, $this->createMock(LoggerInterface::class), $summary);
		$outbox->deliverDigestById(11);
	}
}
