<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Controller;

use OCA\Abonnieren\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class SubscriptionController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private SubscriptionService $subscriptionService,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function submit(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['success' => false, 'message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$action = (string)$this->request->getParam('action', 'list_rules');
		if ($action === 'list_rules') {
			return new DataResponse(['success' => true, 'rules' => $this->buildRuleList($userId)]);
		}

		$nodeIdParam = (string)$this->request->getParam('nodeId', '');
		if (!ctype_digit($nodeIdParam) || (int)$nodeIdParam < 1) {
			return new DataResponse(['success' => false, 'message' => 'Invalid node ID'], Http::STATUS_BAD_REQUEST);
		}
		$nodeId = (int)$nodeIdParam;
		$node = $this->resolveAccessibleNode($userId, $nodeId);
		if ($node === null) {
			return new DataResponse(['success' => false, 'message' => 'File or folder not found'], Http::STATUS_NOT_FOUND);
		}

		if ($action === 'get_rule') {
			return new DataResponse([
				'success' => true,
				'node' => $this->describeNode($node),
				'rule' => $this->subscriptionService->getRule($userId, $nodeId),
			]);
		}

		if ($action === 'delete_rule') {
			$this->subscriptionService->deleteRule($userId, $nodeId);
			return new DataResponse(['success' => true, 'deleted' => true]);
		}

		if ($action !== 'save_rule') {
			return new DataResponse(['success' => false, 'message' => 'Unsupported action'], Http::STATUS_BAD_REQUEST);
		}

		$eventMaskParam = (string)$this->request->getParam('eventMask', '0');
		if (!ctype_digit($eventMaskParam)) {
			return new DataResponse(['success' => false, 'message' => 'Invalid notification event mask'], Http::STATUS_BAD_REQUEST);
		}
		$eventMask = (int)$eventMaskParam;
		if (($eventMask & ~SubscriptionService::ALLOWED_EVENTS) !== 0) {
			return new DataResponse(['success' => false, 'message' => 'Invalid notification event mask'], Http::STATUS_BAD_REQUEST);
		}

		if ($eventMask === 0) {
			$this->subscriptionService->deleteRule($userId, $nodeId);
			return new DataResponse(['success' => true, 'deleted' => true]);
		}

		$isFolder = $node instanceof Folder;
		if (!$isFolder) {
			$eventMask &= ~SubscriptionService::EVENT_UPLOAD;
		}
		if ($eventMask === 0) {
			return new DataResponse(['success' => false, 'message' => 'No supported event selected'], Http::STATUS_BAD_REQUEST);
		}

		$recursive = $isFolder && filter_var(
			$this->request->getParam('recursive', false),
			FILTER_VALIDATE_BOOLEAN,
		);
		$debounceRaw = (string)$this->request->getParam('debounceSeconds', (string)SubscriptionService::DEBOUNCE_SECONDS);
		if (!ctype_digit($debounceRaw)) {
			return new DataResponse(['success' => false, 'message' => 'Invalid suppression window'], Http::STATUS_BAD_REQUEST);
		}
		$excludedExtensions = (string)$this->request->getParam('excludedExtensions', '');
		$channelsRaw = (string)$this->request->getParam('channelMask', (string)SubscriptionService::CHANNEL_EMAIL);
		if (!ctype_digit($channelsRaw)) {
			return new DataResponse(['success' => false, 'message' => 'Invalid notification channels'], Http::STATUS_BAD_REQUEST);
		}

		$quietStart = (string)$this->request->getParam('quietStart', '');
		$quietEnd = (string)$this->request->getParam('quietEnd', '');
		$quietTimezone = (string)$this->request->getParam('quietTimezone', 'UTC');
		$digestMode = (string)$this->request->getParam('digestMode', 'instant');
		$digestTime = (string)$this->request->getParam('digestTime', '08:00');

		try {
			$this->subscriptionService->upsertRule($userId, $nodeId, $eventMask, $recursive,
				(int)$debounceRaw, $excludedExtensions, (int)$channelsRaw,
				$quietStart, $quietEnd, $quietTimezone, $digestMode, $digestTime);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['success' => false, 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse([
			'success' => true,
			'rule' => $this->subscriptionService->getRule($userId, $nodeId),
		]);
	}

	private function resolveAccessibleNode(string $userId, int $nodeId): ?Node {
		try {
			$matches = $this->rootFolder->getUserFolder($userId)->getById($nodeId);
			return $matches[0] ?? null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function buildRuleList(string $userId): array {
		$rules = [];
		foreach ($this->subscriptionService->getRulesByUser($userId) as $rule) {
			$node = $this->resolveAccessibleNode($userId, $rule['nodeId']);
			if ($node === null) {
				continue;
			}
			$rules[] = array_merge($rule, $this->describeNode($node));
		}

		return $rules;
	}

	private function describeNode(Node $node): array {
		$isFolder = $node instanceof Folder;
		$path = $this->getUserRelativePath($node);
		return [
			'nodeId' => (int)$node->getId(),
			'name' => $node->getName(),
			'path' => $path,
			'type' => $isFolder ? 'folder' : 'file',
			'url' => $this->urlGenerator->linkToRouteAbsolute(
				'files.viewcontroller.showFile',
				[
					'dir' => $isFolder ? $path : dirname($path),
					'fileid' => (string)$node->getId(),
				],
			),
		];
	}

	private function getUserRelativePath(Node $node): string {
		$parts = explode('/', trim($node->getPath(), '/'));
		return '/' . implode('/', array_slice($parts, 2));
	}
}
