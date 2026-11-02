<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Notification;

use OCA\Abonnieren\AppInfo\Application;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT = 'subscription_event';

	public function __construct(private IFactory $l10nFactory) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Abonnieren';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::SUBJECT) {
			throw new UnknownNotificationException('Unknown Abonnieren notification');
		}
		$params = $notification->getSubjectParameters();
		$event = (int)($params['event'] ?? 0);
		$isFolder = ($params['kind'] ?? 'file') === 'folder';
		$l10n = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$text = match ($event) {
			SubscriptionService::EVENT_UPLOAD => $isFolder
				? $l10n->t('A subscribed folder was created')
				: $l10n->t('A file was uploaded to a subscribed folder'),
			SubscriptionService::EVENT_MODIFICATION => $l10n->t('A subscribed file was modified'),
			SubscriptionService::EVENT_DELETION => $l10n->t('A subscribed file or folder was deleted'),
			SubscriptionService::EVENT_DOWNLOAD => $isFolder
				? $l10n->t('A subscribed folder was downloaded')
				: $l10n->t('A subscribed file was downloaded'),
			default => throw new UnknownNotificationException('Unknown subscription event'),
		};
		return $notification->setParsedSubject($text);
	}
}
