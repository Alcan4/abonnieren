<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Listeners;

use OCA\Abonnieren\Activity\ActivityPublisher;
use OCA\Abonnieren\Activity\Provider;
use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientMailDispatcher;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Notification\NotificationPublisher;
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
use OCP\Mail\IMailer;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<BeforeNodeReadEvent|BeforeZipCreatedEvent|Event>
 */
class DownloadNotificationListener implements IEventListener {
	private ICache $cache;

	public function __construct(
		private MailOutbox $outbox,
		private IMailer $mailer,
		private RecipientL10N $recipientL10n,
		private LoggerInterface $logger,
		private SubscriptionService $subscriptionService,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
		private ShareContextResolver $shareResolver,
		private ActivityPublisher $activityPublisher,
		private NotificationPublisher $nativePublisher,
		private RecipientMailDispatcher $mailDispatcher,
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
		$actorUserId = $user?->getUID() ?? $this->resolveActorUserId();

		$actorKey = $actorUserId ?? ($share !== null ? 'share_' . $share->getId() : 'anon');
		$cacheKeyBase = implode(':', ['download', (string)$folder->getId(), $actorKey]);
		$this->cache->set('request:' . $this->request->getId(), $folder->getPath(), SubscriptionService::DEBOUNCE_SECONDS);
		$subscriptionNode = $this->shareResolver->resolveOwnerNode($folder, $share);
		$this->notify($share, $folder, $subscriptionNode, $cacheKeyBase, $actorUserId);
	}

	private function handleFileDownload(BeforeNodeReadEvent $event): void {
		$node = $event->getNode();
		if (!$node instanceof File || str_ends_with($node->getName(), '.part') || $this->isInternalSystemPath($node) || !$this->isFileOpenRequest()) {
			return;
		}

		$share = $this->shareResolver->resolveShareContext(
			$node,
			$this->userSession->getUser() === null,
			Constants::PERMISSION_READ,
		);
		$user = $this->userSession->getUser();
		$actorUserId = $user?->getUID() ?? $this->resolveActorUserId();

		$folderPath = $this->cache->get('request:' . $this->request->getId());
		if (is_string($folderPath) && str_starts_with($node->getPath(), $folderPath)) {
			return;
		}

		$actorKey = $actorUserId ?? ($share !== null ? 'share_' . $share->getId() : 'anon');
		$cacheKeyBase = implode(':', ['download', (string)$node->getId(), $actorKey]);
		$subscriptionNode = $this->shareResolver->resolveOwnerNode($node, $share);
		$this->notify($share, $node, $subscriptionNode, $cacheKeyBase, $actorUserId);
	}

	/**
	 * Any real file body read counts as an open/download. Only ignore probes
	 * and folder-list thumbnails — no per-app allowlist.
	 */
	private function isFileOpenRequest(): bool {
		$method = strtoupper($this->request->getMethod());
		if (in_array($method, ['HEAD', 'OPTIONS', 'PROPFIND', 'REPORT'], true)) {
			return false;
		}

		$uri = strtolower($this->request->getRequestUri());
		if (preg_match('~/(apps/files/api/v1/(preview|thumbnail)|apps/files/thumbnail)~', $uri) === 1) {
			return false;
		}

		if (str_contains($uri, '/core/preview')) {
			$x = (int)$this->request->getParam('x', 0);
			$y = (int)$this->request->getParam('y', 0);
			// Small previews are generated while browsing a folder, not opening.
			return max($x, $y) >= 512;
		}

		return true;
	}

	private function notify(?IShare $share, File|Folder $node, Node $subscriptionNode, string $cacheKeyBase, ?string $actorUserId = null): void {
		$policies = $this->subscriptionService->getRecipientPoliciesForNode(
			$subscriptionNode,
			SubscriptionService::EVENT_DOWNLOAD,
			$actorUserId ?? $this->userSession->getUser()?->getUID(),
		);
		$emailRecipients = [];
		$nativeRecipients = [];
		foreach ($policies as $userId => $policy) {
			$seconds = $policy['debounceSeconds'];
			if (($policy['channelMask'] & SubscriptionService::CHANNEL_EMAIL) !== 0
				&& $policy['email'] !== ''
				&& ($seconds === 0 || $this->cache->get($cacheKeyBase . ':' . $userId . ':email') !== true)) {
				$emailRecipients[$userId] = $policy['email'];
			}
			if (($policy['channelMask'] & SubscriptionService::CHANNEL_NOTIFICATION) !== 0
				&& ($seconds === 0 || $this->cache->get($cacheKeyBase . ':' . $userId . ':native') !== true)) {
				$nativeRecipients[] = $userId;
			}
		}
		$nativeSent = $this->nativePublisher->publishForRecipients(
			$subscriptionNode, SubscriptionService::EVENT_DOWNLOAD, $nativeRecipients);
		foreach ($nativeSent as $userId) {
			$seconds = $policies[$userId]['debounceSeconds'];
			if ($seconds > 0) {
				$this->cache->set($cacheKeyBase . ':' . $userId . ':native', true, $seconds);
			}
		}

		$emailQueued = [];
		if ($emailRecipients !== []) {
		try {
			$isFolder = $node instanceof Folder;
			$isPublicLink = $this->shareResolver->isPublicShare($share);
			$path = $this->getUserRelativePath($subscriptionNode);

			$emailQueued = $this->mailDispatcher->deliver(
				$emailRecipients,
				function (string $userId, string $email) use ($share, $node, $subscriptionNode, $actorUserId, $isFolder, $isPublicLink, $path, $policies): void {
					$l10n = $this->recipientL10n->forUser($userId);
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

					$template = $this->mailer->createEMailTemplate('abonnieren_download');
					$template->setSubject($subject);
					$template->addHeader();
					$template->addHeading($subject);
					$template->addBodyText($description);
					$template->addBodyListItem($l10n->t('Name:') . ' ' . $node->getName());
					$template->addBodyListItem($l10n->t('Path:') . ' ' . $path);
					if ($node instanceof File) {
						$template->addBodyListItem($l10n->t('Size:') . ' ' . $this->formatSize($node->getSize()));
					}
					$template->addBodyListItem($l10n->t('Downloaded by:') . ' ' . $this->getActorDisplayName($l10n, $isPublicLink));
					$template->addBodyListItem($l10n->t('Time:') . ' ' . date('d.m.Y H:i:s'));

					// Never retain a public-share capability token in delayed SMTP
					// jobs or email templates. The subscriber must open the file
					// through their own authenticated Nextcloud access.
					$template->addBodyButton(
						$l10n->t('Open file'),
						$this->urlGenerator->linkToRouteAbsolute(
							'files.viewcontroller.showFile',
							[
								'dir' => $subscriptionNode instanceof Folder ? $path : dirname($path),
								'fileid' => (string)$subscriptionNode->getId(),
							],
						),
					);
					$template->addFooter();
					$this->outbox->enqueue($userId, $subscriptionNode, SubscriptionService::EVENT_DOWNLOAD,
						$actorUserId, false, $subject, $template->renderText(), $template->renderHtml(), $policies[$userId]);
				},
				'Could not queue download notification',
				['shareId' => $share !== null ? (int)$share->getId() : null, 'nodeId' => (int)$node->getId()],
			);
			foreach ($emailQueued as $userId) {
				$seconds = $policies[$userId]['debounceSeconds'];
				if ($seconds > 0) {
					$this->cache->set($cacheKeyBase . ':' . $userId . ':email', true, $seconds);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('Could not queue download notification', [
				'app' => 'abonnieren',
				'shareId' => $share !== null ? (int)$share->getId() : null,
				'nodeId' => (int)$node->getId(),
				'exception' => $e,
			]);
		}
		}
		$this->activityPublisher->publishForRecipients(
			Provider::SUBJECT_DOWNLOADED,
			$subscriptionNode,
			$actorUserId ?? $this->userSession->getUser()?->getUID(),
			array_values(array_unique([...$emailQueued, ...$nativeSent])),
		);
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

		return $publicLinkActivity
			? $l10n->t('Anonymous visitor')
			: $l10n->t('Unknown');
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
		$value = $bytes / (1024 ** $power);

		return round($value, 2) . ' ' . $units[$power];
	}
}
