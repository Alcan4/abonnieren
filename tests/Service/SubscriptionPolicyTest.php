<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\SubscriptionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SubscriptionPolicyTest extends TestCase {
	/** @param list<array<string,mixed>> $rows */
	private function service(array $rows, string $email = 'test@example.test'): SubscriptionService {
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':param');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('node_id IN (:param)');
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$accessibleFolder = $this->createMock(Folder::class);
		$accessibleFolder->method('getById')->willReturn([$this->createMock(File::class)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($accessibleFolder);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($email);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		return new SubscriptionService($db, $users, $root);
	}

	private function file(string $name): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(10);
		$parent->method('getParent')->willThrowException(new \RuntimeException('End of hierarchy'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(20);
		$file->method('getName')->willReturn($name);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}

	private function row(string $user, int $nodeId, int $seconds, string $excluded = ''): array {
		return [
			'user_id' => $user,
			'node_id' => $nodeId,
			'event_mask' => SubscriptionService::EVENT_MODIFICATION,
			'recursive' => 1,
			'debounce_seconds' => $seconds,
			'excluded_extensions' => $excluded,
			'channel_mask' => SubscriptionService::CHANNEL_EMAIL,
		];
	}

	public function testSpecificRuleOverridesParentThrottle(): void {
		$service = $this->service([
			$this->row('alice', 10, 600),
			$this->row('alice', 20, 30),
			$this->row('bob', 10, 120),
		]);
		$policies = $service->getRecipientPoliciesForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION, 'editor');
		self::assertSame(30, $policies['alice']['debounceSeconds']);
		self::assertSame(120, $policies['bob']['debounceSeconds']);
		self::assertCount(2, $policies);
	}

	public function testSpecificExtensionExclusionIsNotOverriddenByParent(): void {
		$service = $this->service([
			$this->row('alice', 10, 600),
			$this->row('alice', 20, 0, 'tmp'),
			$this->row('bob', 10, 60),
		]);
		$policies = $service->getRecipientPoliciesForNode(
			$this->file('document.TMP'), SubscriptionService::EVENT_MODIFICATION);
		self::assertArrayNotHasKey('alice', $policies);
		self::assertArrayHasKey('bob', $policies);
	}

	public function testActorIsExcludedAndEmailsMatchPolicies(): void {
		$service = $this->service([
			$this->row('alice', 10, 60),
			$this->row('bob', 10, 60),
		]);
		$emails = $service->getRecipientEmailsForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION, 'alice');
		self::assertSame(['bob' => 'test@example.test'], $emails);
	}
	public function testNotificationOnlySubscriptionDoesNotRequireEmail(): void {
		$row = $this->row('alice', 10, 60);
		$row['channel_mask'] = SubscriptionService::CHANNEL_NOTIFICATION;
		$service = $this->service([$row], '');
		$file = $this->file('document.pdf');

		$policies = $service->getRecipientPoliciesForNode($file, SubscriptionService::EVENT_MODIFICATION);
		self::assertSame(SubscriptionService::CHANNEL_NOTIFICATION, $policies['alice']['channelMask']);
		self::assertSame('', $policies['alice']['email']);
		self::assertSame([], $service->getRecipientEmailsForNode($file, SubscriptionService::EVENT_MODIFICATION));
	}

	public function testSnoozedSpecificRuleMutesRecipientEvenWhenParentRuleIsActive(): void {
		$parent = $this->row('alice', 10, 600);
		$specific = $this->row('alice', 20, 60);
		$specific['snoozed_until'] = time() + 3600;
		$service = $this->service([$parent, $specific]);
		self::assertSame([], $service->getRecipientPoliciesForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION));
	}

	public function testExpiredSpecificRuleDoesNotShadowActiveParent(): void {
		$parent = $this->row('alice', 10, 600);
		$specific = $this->row('alice', 20, 60);
		$specific['expires_at'] = time() - 3600;
		$service = $this->service([$parent, $specific]);
		$policy = $service->getRecipientPoliciesForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION);
		self::assertSame(600, $policy['alice']['debounceSeconds']);
	}

	public function testExpiredParentRuleDoesNotNotifyWithoutAnotherMatchingRule(): void {
		$parent = $this->row('alice', 10, 600);
		$parent['expires_at'] = time() - 3600;
		$service = $this->service([$parent]);
		self::assertSame([], $service->getRecipientEmailsForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION));
	}

	public function testResumedRuleNotifiesAfterSnoozeEnds(): void {
		$row = $this->row('alice', 10, 45);
		$row['snoozed_until'] = time() - 1;
		$service = $this->service([$row]);
		self::assertArrayHasKey('alice', $service->getRecipientPoliciesForNode(
			$this->file('document.pdf'), SubscriptionService::EVENT_MODIFICATION));
	}

	private function nestedFile(string $directory): File {
		$top = $this->createMock(Folder::class);
		$top->method('getId')->willReturn(10);
		$top->method('getName')->willReturn('Subscribed');
		$top->method('getParent')->willThrowException(new \RuntimeException('End of hierarchy'));
		$subdir = $this->createMock(Folder::class);
		$subdir->method('getId')->willReturn(15);
		$subdir->method('getName')->willReturn($directory);
		$subdir->method('getParent')->willReturn($top);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(20);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getParent')->willReturn($subdir);
		return $file;
	}

	public function testFolderExclusionFiltersDescendantsButNotSimilarlyNamedFolders(): void {
		$parent = $this->row('alice', 10, 0);
		$parent['excluded_folders'] = 'Archive';
		$service = $this->service([$parent]);
		self::assertSame([], $service->getRecipientPoliciesForNode(
			$this->nestedFile('Archive'), SubscriptionService::EVENT_MODIFICATION));
		self::assertArrayHasKey('alice', $service->getRecipientPoliciesForNode(
			$this->nestedFile('Archive2'), SubscriptionService::EVENT_MODIFICATION));
	}

	public function testCloserSubscriptionCanOverrideAnAncestorExclusion(): void {
		$parent = $this->row('alice', 10, 0);
		$parent['excluded_folders'] = 'Archive';
		$child = $this->row('alice', 15, 0);
		$service = $this->service([$parent, $child]);
		self::assertArrayHasKey('alice', $service->getRecipientPoliciesForNode(
			$this->nestedFile('Archive'), SubscriptionService::EVENT_MODIFICATION));
	}

}
