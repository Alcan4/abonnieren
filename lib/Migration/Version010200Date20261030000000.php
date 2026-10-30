<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add delivery leases, bounded attempts and diagnostic state to the outbox. */
class Version010200Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_mail_outbox')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_mail_outbox');
		$columns = [
			'attempts' => ['integer', ['notnull' => true, 'default' => 0]],
			'next_attempt_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'lease_until' => ['bigint', ['notnull' => true, 'default' => 0]],
			'lease_token' => ['string', ['length' => 32, 'notnull' => false]],
			'updated_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'completed_at' => ['bigint', ['notnull' => true, 'default' => 0]],
		];
		foreach ($columns as $name => [$type, $options]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, $options);
			}
		}
		if (!$table->hasIndex('abonnieren_due_idx')) {
			$table->addIndex(['status', 'next_attempt_at'], 'abonnieren_due_idx');
		}
		if (!$table->hasIndex('abonnieren_lease_idx')) {
			$table->addIndex(['status', 'lease_until'], 'abonnieren_lease_idx');
		}
		return $schema;
	}
}
