<?php

declare(strict_types=1);

namespace OCA\Abonnieren\BackgroundJob;

use OCA\Abonnieren\Service\MailOutbox;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** Dispatches one email digest window without exposing notification content in args. */
class DeliverDigestJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private MailOutbox $outbox) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (is_array($argument) && isset($argument['id']) && (int)$argument['id'] > 0) {
			$this->outbox->deliverDigestById((int)$argument['id']);
		}
	}
}
