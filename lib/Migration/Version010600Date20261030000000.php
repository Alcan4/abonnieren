<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Quiet hours are opted into per subscription; default behavior is unchanged. */
class Version010600Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('abonnieren_object_rules')) {
			$table = $schema->getTable('abonnieren_object_rules');
			if (!$table->hasColumn('quiet_start')) {
				$table->addColumn('quiet_start', 'string', ['length' => 5, 'notnull' => true, 'default' => '']);
			}
			if (!$table->hasColumn('quiet_end')) {
				$table->addColumn('quiet_end', 'string', ['length' => 5, 'notnull' => true, 'default' => '']);
			}
			if (!$table->hasColumn('quiet_timezone')) {
				$table->addColumn('quiet_timezone', 'string', ['length' => 64, 'notnull' => true, 'default' => 'UTC']);
			}
		}
		if ($schema->hasTable('abonnieren_mail_outbox')) {
			$table = $schema->getTable('abonnieren_mail_outbox');
			if (!$table->hasColumn('quiet_start')) {
				$table->addColumn('quiet_start', 'string', ['length' => 5, 'notnull' => true, 'default' => '']);
			}
			if (!$table->hasColumn('quiet_end')) {
				$table->addColumn('quiet_end', 'string', ['length' => 5, 'notnull' => true, 'default' => '']);
			}
			if (!$table->hasColumn('quiet_timezone')) {
				$table->addColumn('quiet_timezone', 'string', ['length' => 64, 'notnull' => true, 'default' => 'UTC']);
			}
		}
		return $schema;
	}
}
