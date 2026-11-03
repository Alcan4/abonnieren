<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Integration;

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCA\Abonnieren\BackgroundJob\ReconcileOutboxJob;
use OCA\Abonnieren\Listeners\DownloadNotificationListener;
use OCA\Abonnieren\Listeners\FileUploadListener;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;

/** Check real Nextcloud service auto-wiring, not only constructor mocks. */
final class RuntimeWiringTest extends TestCase {
	public function testListenersAndBackgroundJobsResolveFromInstalledApp(): void {
		$app = new \OCA\Abonnieren\AppInfo\Application();
		$container = $app->getContainer();
		foreach ([
			SubscriptionService::class,
			MailOutbox::class,
			FileUploadListener::class,
			DownloadNotificationListener::class,
			DeliverMailJob::class,
			ReconcileOutboxJob::class,
		] as $className) {
			self::assertInstanceOf($className, $container->get($className), $className);
		}
	}

	public function testReconcilerIsDeclaredAsABackgroundJob(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		self::assertNotFalse($xml);
		self::assertSame(ReconcileOutboxJob::class, (string)$xml->{'background-jobs'}->job);
	}
}
