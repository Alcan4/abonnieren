<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add per-subscription suppression and file-extension exclusion preferences. */
class Version010300Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_object_rules')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_object_rules');
		if (!$table->hasColumn('debounce_seconds')) {
			$table->addColumn('debounce_seconds', 'integer', ['notnull' => true, 'default' => 600]);
		}
		if (!$table->hasColumn('excluded_extensions')) {
			$table->addColumn('excluded_extensions', 'string', ['length' => 512, 'notnull' => false, 'default' => null]);
		}
		return $schema;
	}
}
