<?php

declare(strict_types=1);

namespace OCA\Abonnieren\AppInfo;

use OCA\Abonnieren\BackgroundJob\ReconcileOutboxJob;
use OCA\Abonnieren\Listeners\DownloadNotificationListener;
use OCA\Abonnieren\Listeners\FileUploadListener;
use OCA\Abonnieren\Listeners\LoadAdditionalScriptsListener;
use OCA\Abonnieren\Notification\Notifier;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'abonnieren';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		foreach ([NodeCreatedEvent::class, NodeDeletedEvent::class, NodeWrittenEvent::class] as $event) {
			$context->registerEventListener($event, FileUploadListener::class);
		}
		$context->registerEventListener(BeforeNodeReadEvent::class, DownloadNotificationListener::class);
		$context->registerEventListener(BeforeZipCreatedEvent::class, DownloadNotificationListener::class);
		$context->registerService(SubscriptionService::class, static fn (ContainerInterface $c) => new SubscriptionService(
			$c->get(IDBConnection::class),
			$c->get(IUserManager::class),
			$c->get(IRootFolder::class),
		));
		$context->registerService(LoadAdditionalScriptsListener::class, static fn (ContainerInterface $c) => new LoadAdditionalScriptsListener(
			$c->get(IAppManager::class),
		));

		// Register by class name without probing the Files app during bootstrap.
		// The Files namespace is not guaranteed to be autoloadable yet while apps
		// are registered, but the dispatcher can safely accept the event name.
		$context->registerEventListener(
			'OCA\\Files\\Event\\LoadAdditionalScriptsEvent',
			LoadAdditionalScriptsListener::class,
		);
	}

	public function boot(IBootContext $context): void {
		$jobs = $context->getServerContainer()->get(IJobList::class);
		if (!$jobs->has(ReconcileOutboxJob::class, null)) {
			$jobs->add(ReconcileOutboxJob::class);
		}
	}
}
