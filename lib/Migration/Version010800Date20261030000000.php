<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Zero means no expiration or snooze. Legacy subscriptions remain permanent and active. */
class Version010800Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_object_rules')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_object_rules');
		if (!$table->hasColumn('expires_at')) {
			$table->addColumn('expires_at', 'bigint', ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('snoozed_until')) {
			$table->addColumn('snoozed_until', 'bigint', ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}
}
