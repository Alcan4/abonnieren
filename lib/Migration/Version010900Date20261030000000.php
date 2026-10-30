<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Folder exclusions are relative to the rule's folder, with no patterns by default. */
class Version010900Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_object_rules')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_object_rules');
		if (!$table->hasColumn('excluded_folders')) {
			$table->addColumn('excluded_folders', 'string', ['length' => 1024, 'notnull' => true, 'default' => '']);
		}
		return $schema;
	}
}
