<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Notification;

use OCA\Abonnieren\Notification\NotificationPublisher;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationPublisherTest extends TestCase {
	public function testPublishesNativeNotificationWithRecipientVisibleLink(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);
		$manager = $this->createMock(IManager::class);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify')->with($notification);
		$url = $this->createMock(IURLGenerator::class);
		$url->expects($this->once())->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['dir' => '/Shared', 'fileid' => '42'])
			->willReturn('https://cloud.example.test/apps/files/files/42');
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example.test/apps/files/files/42')->willReturnSelf();
		$paths = $this->createMock(RecipientVisiblePath::class);
		$paths->method('pathFor')->with('subscriber', $node)->willReturn('/Shared/test.txt');
		$publisher = new NotificationPublisher($manager, $url, $this->createMock(LoggerInterface::class), $paths);

		self::assertSame(['subscriber'], $publisher->publishForRecipients(
			$node, SubscriptionService::EVENT_MODIFICATION, ['subscriber', 'subscriber']));
	}

	public function testFailureForOneRecipientDoesNotBlockTheNext(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);
		$manager = $this->createMock(IManager::class);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$manager->method('createNotification')->willReturn($notification);
		$count = 0;
		$manager->method('notify')->willReturnCallback(static function () use (&$count): void {
			$count++;
			if ($count === 1) {
				throw new \RuntimeException('Push service temporary failure');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$publisher = new NotificationPublisher($manager, $this->createMock(IURLGenerator::class), $logger, $this->createMock(RecipientVisiblePath::class));

		self::assertSame(['second'], $publisher->publishForRecipients(
			$node, SubscriptionService::EVENT_DELETION, ['first', 'second']));
	}
}
