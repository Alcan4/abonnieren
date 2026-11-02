<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Email stays enabled by default; native notification is opt-in per rule. */
class Version010400Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_object_rules')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_object_rules');
		if (!$table->hasColumn('channel_mask')) {
			$table->addColumn('channel_mask', 'integer', ['notnull' => true, 'default' => 1]);
		}
		return $schema;
	}
}
