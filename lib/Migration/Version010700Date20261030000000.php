<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Email digests are opt-in; existing subscriptions send individually. */
class Version010700Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('abonnieren_object_rules')) {
			$table = $schema->getTable('abonnieren_object_rules');
			if (!$table->hasColumn('digest_mode')) {
				$table->addColumn('digest_mode', 'string', ['length' => 8, 'notnull' => true, 'default' => 'instant']);
			}
			if (!$table->hasColumn('digest_time')) {
				$table->addColumn('digest_time', 'string', ['length' => 5, 'notnull' => true, 'default' => '08:00']);
			}
		}
		if ($schema->hasTable('abonnieren_mail_outbox')) {
			$table = $schema->getTable('abonnieren_mail_outbox');
			if (!$table->hasColumn('digest_key')) {
				$table->addColumn('digest_key', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
			}
			if (!$table->hasIndex('abonnieren_digest_idx')) {
				$table->addIndex(['digest_key', 'status', 'next_attempt_at'], 'abonnieren_digest_idx');
			}
		}
		return $schema;
	}
}
