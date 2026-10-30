<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Group only newly queued upload mail; old outbox rows stay ungrouped. */
class Version010500Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_mail_outbox')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_mail_outbox');
		if (!$table->hasColumn('batch_key')) {
			$table->addColumn('batch_key', 'string', ['length' => 64, 'notnull' => true, 'default' => '']);
		}
		if (!$table->hasColumn('batch_until')) {
			$table->addColumn('batch_until', 'bigint', ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('item_name')) {
			$table->addColumn('item_name', 'string', ['length' => 255, 'notnull' => true, 'default' => '']);
		}
		if (!$table->hasIndex('abonnieren_batch_idx')) {
			$table->addIndex(['batch_key', 'status', 'next_attempt_at'], 'abonnieren_batch_idx');
		}
		return $schema;
	}
}
