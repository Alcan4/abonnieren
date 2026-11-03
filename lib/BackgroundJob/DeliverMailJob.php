<?php

declare(strict_types=1);

namespace OCA\Abonnieren\BackgroundJob;

use OCA\Abonnieren\Service\MailOutbox;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** Sends one persisted email outside the file operation request. */
class DeliverMailJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private MailOutbox $outbox) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!is_array($argument) || !isset($argument['id']) || (int)$argument['id'] < 1) {
			return;
		}

		$this->outbox->deliverById((int)$argument['id']);
	}
}
