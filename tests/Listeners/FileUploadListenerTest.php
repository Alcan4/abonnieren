<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Listeners;

use OCA\Abonnieren\Activity\ActivityPublisher;
use OCA\Abonnieren\Listeners\FileUploadListener;
use OCA\Abonnieren\Notification\NotificationPublisher;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientMailDispatcher;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCA\Abonnieren\Service\ShareContextResolver;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileUploadListenerTest extends TestCase {
	public function testCreatedAndWrittenPairProducesOnlyUploadLookup(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getPath')->willReturn('/subscriber/files/report.pdf');

		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->expects($this->once())->method('getRecipientPoliciesForNode')
			->with($file, SubscriptionService::EVENT_UPLOAD, 'uploader')
			->willReturn([]);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('uploader');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$shares = $this->createMock(ShareContextResolver::class);
		$shares->method('resolveShareContext')->willReturn(null);
		$shares->method('isPublicShare')->willReturn(false);
		$shares->method('resolveOwnerNode')->willReturn($file);
		$native = $this->createMock(NotificationPublisher::class);
		$native->expects($this->once())->method('publishForRecipients')
			->with($file, SubscriptionService::EVENT_UPLOAD, [])->willReturn([]);
		$activity = $this->createMock(ActivityPublisher::class);
		$activity->expects($this->once())->method('publishForRecipients');

		$listener = new FileUploadListener(
			$this->createMock(MailOutbox::class),
			$this->createMock(IMailer::class),
			$this->createMock(RecipientL10N::class),
			$this->createMock(LoggerInterface::class),
			$subscriptions,
			$this->createMock(IURLGenerator::class),
			$cacheFactory,
			$userSession,
			$shares,
			$activity,
			$native,
			$this->createMock(RecipientVisiblePath::class),
			$this->createMock(RecipientMailDispatcher::class),
		);
		$listener->handle(new NodeCreatedEvent($file));
		$listener->handle(new NodeWrittenEvent($file));
	}
}
