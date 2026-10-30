<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use Psr\Log\LoggerInterface;

/** Isolates mail failures so one recipient cannot block later subscribers. */
class RecipientMailDispatcher {
	public function __construct(private LoggerInterface $logger) {
	}

	/**
	 * @param array<string, string> $recipients Email addresses keyed by user ID.
	 * @param callable(string, string): void $sendMessage Sends to one recipient.
	 * @param array<string, mixed> $context Common diagnostic context (no file details).
	 * @return list<string> User IDs whose mailer send calls completed successfully.
	 */
	public function deliver(array $recipients, callable $sendMessage, string $failureMessage, array $context = []): array {
		$sentUserIds = [];
		foreach ($recipients as $userId => $email) {
			try {
				$sendMessage($userId, $email);
				$sentUserIds[] = $userId;
			} catch (\Throwable $e) {
				$this->logger->error($failureMessage, [
					'app' => 'abonnieren',
					...$context,
					'recipientUserId' => $userId,
					'exception' => $e,
				]);
			}
		}

		return $sentUserIds;
	}
}
