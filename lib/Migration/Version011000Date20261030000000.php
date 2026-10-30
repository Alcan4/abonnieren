<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Record the subscription that authorized a queued deletion email. */
class Version011000Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_mail_outbox')) {
			return null;
		}
		$table = $schema->getTable('abonnieren_mail_outbox');
		if (!$table->hasColumn('source_rule_id')) {
			$table->addColumn('source_rule_id', 'bigint', ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('source_rule_hash')) {
			$table->addColumn('source_rule_hash', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
		}
		if (!$table->hasColumn('source_rule_expires_at')) {
			$table->addColumn('source_rule_expires_at', 'bigint', ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}
}
