<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Listeners;

use OCA\Abonnieren\Activity\ActivityPublisher;
use OCA\Abonnieren\Activity\Provider;
use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientMailDispatcher;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\ShareContextResolver;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeCreatedEvent|NodeDeletedEvent|NodeWrittenEvent|Event> */
class FileUploadListener implements IEventListener {
	private ICache $cache;

	public function __construct(
		private MailOutbox $outbox,
		private IMailer $mailer,
		private RecipientL10N $recipientL10n,
		private LoggerInterface $logger,
		private SubscriptionService $subscriptionService,
		private IURLGenerator $urlGenerator,
		ICacheFactory $cacheFactory,
		private IUserSession $userSession,
		private ShareContextResolver $shareResolver,
		private ActivityPublisher $activityPublisher,
		private RecipientMailDispatcher $mailDispatcher,
	) {
		$this->cache = $cacheFactory->createDistributed('abonnieren_event_debounce');
	}

	public function handle(Event $event): void {
		[$eventName, $eventBit] = match (true) {
			$event instanceof NodeCreatedEvent => ['created', SubscriptionService::EVENT_UPLOAD],
			$event instanceof NodeDeletedEvent => ['deleted', SubscriptionService::EVENT_DELETION],
			$event instanceof NodeWrittenEvent => ['modified', SubscriptionService::EVENT_MODIFICATION],
			default => [null, 0],
		};
		if ($eventName === null) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File && !$node instanceof Folder) {
			return;
		}
		if (str_ends_with($node->getName(), '.part') || $this->isInternalSystemPath($node)) {
			return;
		}

		// Folder write events are implementation details and would create noisy
		// modification emails. Folder creation and deletion remain meaningful.
		if ($event instanceof NodeWrittenEvent && $node instanceof Folder) {
			return;
		}

		$user = $this->userSession->getUser();
		$actorUserId = $user?->getUID() ?? $this->resolveActorUserId();
		$share = $this->shareResolver->resolveShareContext(
			$node,
			$user === null,
			Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE,
		);
		$isPublicLink = $this->shareResolver->isPublicShare($share);

		$actorKey = $actorUserId ?? ($share !== null ? 'share_' . $share->getId() : 'anon');
		$category = match ($eventName) {
			'created' => 'upload',
			'deleted' => 'deletion',
			default => 'modification',
		};
		$cacheKey = implode(':', [$category, (string)$node->getId(), $actorKey]);
		if ($this->cache->get($cacheKey) === true) {
			return;
		}
		$this->cache->set($cacheKey, true, SubscriptionService::DEBOUNCE_SECONDS);

		$subscriptionNode = $this->shareResolver->resolveOwnerNode($node, $share);
		$recipients = $this->subscriptionService->getRecipientEmailsForNode(
			$subscriptionNode,
			$eventBit,
			$actorUserId,
		);
		if ($recipients !== []) {
			$this->queueNotification($subscriptionNode, $recipients, $eventName, $eventBit, $actorUserId, $isPublicLink);
			$activitySubject = match ($eventName) {
				'created' => Provider::SUBJECT_CREATED,
				'deleted' => Provider::SUBJECT_DELETED,
				default => Provider::SUBJECT_MODIFIED,
			};
			$this->activityPublisher->publishForRecipients(
				$activitySubject,
				$subscriptionNode,
				$actorUserId,
				array_keys($recipients),
			);
		}

		if ($event instanceof NodeDeletedEvent) {
			$this->subscriptionService->deleteRulesForNode((int)$node->getId());
		}
	}

	/** @param array<string, string> $recipients */
	private function queueNotification(Node $node, array $recipients, string $eventName, int $eventBit, ?string $actorUserId, bool $publicLinkActivity): void {
		try {
			$isFolder = $node instanceof Folder;
			$path = $this->getUserRelativePath($node);

			$this->mailDispatcher->deliver(
				$recipients,
				function (string $userId, string $email) use ($node, $eventName, $eventBit, $actorUserId, $isFolder, $path, $publicLinkActivity): void {
					$l10n = $this->recipientL10n->forUser($userId);
					$subject = $this->getSubject($l10n, $isFolder, $eventName);
					$description = $this->getDescription($l10n, $publicLinkActivity);

					$template = $this->mailer->createEMailTemplate('abonnieren_file_event');
					$template->setSubject($subject);
					$template->addHeader();
					$template->addHeading($subject);
					$template->addBodyText($description);
					$template->addBodyListItem($l10n->t('Name:') . ' ' . $node->getName());
					$template->addBodyListItem($l10n->t('Path:') . ' ' . dirname($path));
					$template->addBodyListItem($l10n->t('Size:') . ' ' . $this->formatSize($node->getSize()));
					$template->addBodyListItem($l10n->t('Type:') . ' ' . ($node instanceof File ? $node->getMimeType() : $l10n->t('Folder')));
					$template->addBodyListItem($l10n->t('Changed by:') . ' ' . $this->getActorDisplayName($l10n, $publicLinkActivity));
					$template->addBodyListItem($l10n->t('Time:') . ' ' . date('d.m.Y H:i:s'));

					if ($eventName !== 'deleted') {
						$template->addBodyButton(
							$l10n->t('Open file'),
							$this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
								'dir' => $isFolder ? $path : dirname($path),
								'fileid' => (string)$node->getId(),
							]),
						);
					}
					$template->addFooter();
					$this->outbox->enqueue($userId, $node, $eventBit, $actorUserId, $eventName === 'deleted',
						$subject, $template->renderText(), $template->renderHtml());
				},
				'Could not queue file event notification',
				['nodeId' => (int)$node->getId(), 'event' => $eventName],
			);
		} catch (\Throwable $e) {
			$this->logger->error('Could not queue file event notification', [
				'app' => 'abonnieren',
				'nodeId' => (int)$node->getId(),
				'event' => $eventName,
				'exception' => $e,
			]);
		}
	}

	private function getSubject(IL10N $l10n, bool $isFolder, string $eventName): string {
		return match ($eventName) {
			'created' => $l10n->t($isFolder ? 'Folder created' : 'File uploaded'),
			'deleted' => $l10n->t($isFolder ? 'Folder deleted' : 'File deleted'),
			default => $l10n->t($isFolder ? 'Folder modified' : 'File modified'),
		};
	}

	private function getDescription(IL10N $l10n, bool $publicLinkActivity): string {
		return $publicLinkActivity
			? $l10n->t('The event occurred through a public share link.')
			: $l10n->t('The event occurred within the scope of a subscription.');
	}

	private function getUserRelativePath(Node $node): string {
		$parts = explode('/', trim($node->getPath(), '/'));
		return '/' . implode('/', array_slice($parts, 2));
	}

	private function getActorDisplayName(IL10N $l10n, bool $publicLinkActivity): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getDisplayName();
		}
		return $publicLinkActivity ? $l10n->t('Anonymous visitor') : $l10n->t('Unknown');
	}

	/** Editors such as ONLYOFFICE may set OC_User without a full IUserSession. */
	private function resolveActorUserId(): ?string {
		if (!class_exists(\OC_User::class)) {
			return null;
		}
		$uid = \OC_User::getUser();
		return is_string($uid) && $uid !== '' ? $uid : null;
	}

	private function isInternalSystemPath(Node $node): bool {
		$path = $node->getPath();
		return str_contains($path, '/appdata_')
			|| str_contains($path, '/files_trashbin/')
			|| str_contains($path, '/files_versions/')
			|| str_contains($path, '/thumbnails/');
	}

	private function formatSize(int|float $bytes): string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$bytes = max($bytes, 0);
		$power = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
		$power = min($power, count($units) - 1);
		return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
	}
}
