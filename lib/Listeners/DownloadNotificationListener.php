<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Listeners;

use OCA\Abonnieren\Activity\ActivityPublisher;
use OCA\Abonnieren\Activity\Provider;
use OCA\Abonnieren\Service\DownloadRequestPolicy;
use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCA\Abonnieren\Service\ShareContextResolver;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<BeforeNodeReadEvent|BeforeZipCreatedEvent|Event>
 */
class DownloadNotificationListener implements IEventListener {
	private ICache $cache;

	public function __construct(
		private IMailer $mailer,
		private RecipientL10N $recipientL10n,
		private LoggerInterface $logger,
		private SubscriptionService $subscriptionService,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
		private ShareContextResolver $shareResolver,
		private ActivityPublisher $activityPublisher,
		private RecipientVisiblePath $recipientVisiblePath,
		private DownloadRequestPolicy $downloadRequestPolicy,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('abonnieren_event_debounce');
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeZipCreatedEvent) {
			$this->handleZipDownload($event);
			return;
		}

		if ($event instanceof BeforeNodeReadEvent) {
			$this->handleFileDownload($event);
		}
	}

	private function handleZipDownload(BeforeZipCreatedEvent $event): void {
		// If explicit files were selected, their BeforeNodeReadEvent instances
		// create the individual notifications. An empty list means that the
		// whole folder was requested as one ZIP download.
		if (count($event->getFiles()) !== 0) {
			return;
		}

		$folder = $event->getFolder();
		if (!$folder instanceof Folder || $this->isInternalSystemPath($folder)) {
			return;
		}

		$share = $this->shareResolver->resolveShareContext(
			$folder,
			$this->userSession->getUser() === null,
			Constants::PERMISSION_READ,
		);
		$user = $this->userSession->getUser();
		$actorUserId = $user?->getUID();

		$actorKey = $actorUserId ?? ($share !== null ? 'share_' . $share->getId() : 'anon');
		$cacheKey = implode(':', ['download', (string)$folder->getId(), $actorKey]);
		if ($this->cache->get($cacheKey) === true) {
			return;
		}
		$this->cache->set($cacheKey, true, SubscriptionService::DEBOUNCE_SECONDS);

		$this->cache->set('request:' . $this->request->getId(), $folder->getPath(), SubscriptionService::DEBOUNCE_SECONDS);
		$subscriptionNode = $this->shareResolver->resolveOwnerNode($folder, $share);
		$this->notify($share, $folder, $subscriptionNode, $actorUserId);
	}

	private function handleFileDownload(BeforeNodeReadEvent $event): void {
		$node = $event->getNode();
		if (!$node instanceof File || str_ends_with($node->getName(), '.part') || $this->isInternalSystemPath($node) || !$this->downloadRequestPolicy->isFileDownload()) {
			return;
		}

		$share = $this->shareResolver->resolveShareContext(
			$node,
			$this->userSession->getUser() === null,
			Constants::PERMISSION_READ,
		);
		$user = $this->userSession->getUser();
		$actorUserId = $user?->getUID();

		$folderPath = $this->cache->get('request:' . $this->request->getId());
		if (is_string($folderPath) && str_starts_with($node->getPath(), $folderPath)) {
			return;
		}

		$actorKey = $actorUserId ?? ($share !== null ? 'share_' . $share->getId() : 'anon');
		$cacheKey = implode(':', ['download', (string)$node->getId(), $actorKey]);
		if ($this->cache->get($cacheKey) === true) {
			return;
		}
		$this->cache->set($cacheKey, true, SubscriptionService::DEBOUNCE_SECONDS);

		$subscriptionNode = $this->shareResolver->resolveOwnerNode($node, $share);
		$this->notify($share, $node, $subscriptionNode, $actorUserId);
	}

	private function notify(?IShare $share, File|Folder $node, Node $subscriptionNode, ?string $actorUserId = null): void {
		$recipients = $this->subscriptionService->getRecipientEmailsForNode(
			$subscriptionNode,
			SubscriptionService::EVENT_DOWNLOAD,
			$actorUserId ?? $this->userSession->getUser()?->getUID(),
		);
		if ($recipients === []) {
			return;
		}

		$this->activityPublisher->publishForRecipients(
			Provider::SUBJECT_DOWNLOADED,
			$subscriptionNode,
			$actorUserId ?? $this->userSession->getUser()?->getUID(),
			array_keys($recipients),
		);

		try {
			$isFolder = $node instanceof Folder;
			$isPublicLink = $this->shareResolver->isPublicShare($share);
			foreach ($recipients as $userId => $email) {
				$l10n = $this->recipientL10n->forUser($userId);
				$path = $this->recipientVisiblePath->pathFor($userId, $subscriptionNode);
				$subject = $isFolder
					? ($isPublicLink
						? $l10n->t('Public share folder downloaded')
						: $l10n->t('Folder downloaded'))
					: ($isPublicLink
						? $l10n->t('Public share file downloaded')
						: $l10n->t('File downloaded'));
				$description = $isFolder
					? ($isPublicLink
						? $l10n->t('A folder was downloaded through one of your public share links.')
						: $l10n->t('A folder covered by one of your subscriptions was downloaded.'))
					: ($isPublicLink
						? $l10n->t('A file was downloaded through one of your public share links.')
						: $l10n->t('A file covered by one of your subscriptions was downloaded.'));

				$message = $this->mailer->createMessage();
				$message->setSubject($subject);
				$template = $this->mailer->createEMailTemplate('abonnieren_download');
				$template->setSubject($subject);
				$template->addHeader();
				$template->addHeading($subject);
				$template->addBodyText($description);
				$template->addBodyListItem($l10n->t('Name:') . ' ' . $node->getName());
				if ($path !== null) {
					$template->addBodyListItem($l10n->t('Path:') . ' ' . $path);
				}
				if ($node instanceof File) {
					$template->addBodyListItem($l10n->t('Size:') . ' ' . $this->formatSize($node->getSize()));
				}
				$template->addBodyListItem($l10n->t('Downloaded by:') . ' ' . $this->getActorDisplayName($l10n, $isPublicLink));
				$template->addBodyListItem($l10n->t('Time:') . ' ' . date('d.m.Y H:i:s'));

				// Do not copy a public-share capability token into subscriber mail.
				// Link only through the recipient's authenticated Nextcloud access.
				if ($path !== null) {
					$template->addBodyButton(
						$l10n->t('Open file'),
						$this->urlGenerator->linkToRouteAbsolute(
							'files.viewcontroller.showFile',
							[
								'dir' => $subscriptionNode instanceof Folder
									? $path
									: dirname($path),
								'fileid' => (string)$subscriptionNode->getId(),
							],
						),
					);
				}
				$template->addFooter();
				$message->setBody($template->renderText(), 'text/plain');
				$message->setHtmlBody($template->renderHtml());
				$message->setTo([$email]);
				$this->mailer->send($message);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Could not send download notification', [
				'app' => 'abonnieren',
				'shareId' => $share !== null ? (int)$share->getId() : null,
				'nodeId' => (int)$node->getId(),
				'exception' => $e,
			]);
		}
	}

	private function getActorDisplayName(IL10N $l10n, bool $publicLinkActivity): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getDisplayName();
		}

		return $publicLinkActivity
			? $l10n->t('Anonymous visitor')
			: $l10n->t('Unknown');
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
		$value = $bytes / (1024 ** $power);

		return round($value, 2) . ' ' . $units[$power];
	}
}
