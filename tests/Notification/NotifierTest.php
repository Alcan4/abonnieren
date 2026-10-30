<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Notification;

use OCA\Abonnieren\AppInfo\Application;
use OCA\Abonnieren\Notification\Notifier;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	public function testPreparesLocalizedEventSubject(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with(Application::APP_ID, 'en')->willReturn($l10n);
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn(Notifier::SUBJECT);
		$notification->method('getSubjectParameters')->willReturn([
			'event' => SubscriptionService::EVENT_DOWNLOAD, 'kind' => 'file',
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('A subscribed file was downloaded')->willReturnSelf();

		$notifier = new Notifier($factory);
		self::assertSame(Application::APP_ID, $notifier->getID());
		self::assertSame($notification, $notifier->prepare($notification, 'en'));
	}

	public function testRejectsOtherApplicationsNotifications(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('other_app');
		$this->expectException(UnknownNotificationException::class);
		(new Notifier($this->createMock(IFactory::class)))->prepare($notification, 'en');
	}
}
