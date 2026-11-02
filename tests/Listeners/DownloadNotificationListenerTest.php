<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Listeners;

use OCA\Abonnieren\Activity\ActivityPublisher;
use OCA\Abonnieren\Listeners\DownloadNotificationListener;
use OCA\Abonnieren\Notification\NotificationPublisher;
use OCA\Abonnieren\Service\DownloadRequestPolicy;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientMailDispatcher;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCA\Abonnieren\Service\ShareContextResolver;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DownloadNotificationListenerTest extends TestCase {
	public function testInternalReadProducesNoNotificationSideEffects(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('synthetic.docx');
		$file->method('getPath')->willReturn('/testowner/files/synthetic.docx');

		$requestPolicy = $this->createMock(DownloadRequestPolicy::class);
		$requestPolicy->expects($this->once())->method('isFileDownload')->willReturn(false);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->expects($this->never())->method('getRecipientPoliciesForNode');
		$outbox = $this->createMock(MailOutbox::class);
		$outbox->expects($this->never())->method('enqueue');
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createEMailTemplate');
		$native = $this->createMock(NotificationPublisher::class);
		$native->expects($this->never())->method('publishForRecipients');
		$activity = $this->createMock(ActivityPublisher::class);
		$activity->expects($this->never())->method('publishForRecipients');
		$dispatcher = $this->createMock(RecipientMailDispatcher::class);
		$dispatcher->expects($this->never())->method('deliver');
		$shareResolver = $this->createMock(ShareContextResolver::class);
		$shareResolver->expects($this->never())->method('resolveShareContext');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$listener = new DownloadNotificationListener(
			$outbox,
			$mailer,
			$this->createMock(RecipientL10N::class),
			$this->createMock(LoggerInterface::class),
			$subscriptions,
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IRequest::class),
			$shareResolver,
			$activity,
			$native,
			$this->createMock(RecipientVisiblePath::class),
			$dispatcher,
			$requestPolicy,
			$cacheFactory,
		);

		$listener->handle(new BeforeNodeReadEvent($file));
	}
}
