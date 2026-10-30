<?php

declare(strict_types=1);

namespace OCA\Abonnieren\BackgroundJob;

use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\MailRetryPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Reschedules due mail and recovers jobs lost after a worker crash. */
class ReconcileOutboxJob extends TimedJob {
	public function __construct(ITimeFactory $time, private MailOutbox $outbox) {
		parent::__construct($time);
		$this->setInterval(MailRetryPolicy::SWEEP_INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		$this->outbox->reconcile();
	}
}
