<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Notification;

use OCA\Abonnieren\AppInfo\Application;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/** Native Nextcloud notifications, independent of SMTP and Activity. */
class NotificationPublisher {
	public function __construct(
		private IManager $manager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private RecipientVisiblePath $recipientVisiblePath,
	) {
	}

	/**
	 * @param list<string> $recipients
	 * @return list<string> User IDs accepted by the notification manager.
	 */
	public function publishForRecipients(Node $node, int $event, array $recipients): array {
		$sent = [];
		foreach (array_unique($recipients) as $userId) {
			if ($userId === '' || (int)$node->getId() <= 0) {
				continue;
			}
			try {
				$notification = $this->manager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('files', (string)$node->getId())
					->setSubject(Notifier::SUBJECT, [
						'event' => $event,
						'kind' => $node instanceof Folder ? 'folder' : 'file',
					]);
				if ($event !== \OCA\Abonnieren\Service\SubscriptionService::EVENT_DELETION) {
					$path = $this->recipientVisiblePath->pathFor($userId, $node);
					if ($path !== null) {
						$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
							'dir' => $node instanceof Folder ? $path : dirname($path),
							'fileid' => (string)$node->getId(),
						]));
					}
				}
				$this->manager->notify($notification);
				$sent[] = $userId;
			} catch (\Throwable $e) {
				$this->logger->error('Could not publish subscription notification', [
					'app' => Application::APP_ID,
					'nodeId' => (int)$node->getId(),
					'recipientUserId' => $userId,
					'exception' => $e,
				]);
			}
		}
		return $sent;
	}

}
