<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add per-subscription controls and grouped-delivery state. */
class Version011200Date20261105000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('abonnieren_object_rules')) {
			$this->addMissingColumns($schema->getTable('abonnieren_object_rules'), [
				'debounce_seconds' => ['integer', ['notnull' => true, 'default' => 600]],
				'excluded_extensions' => ['string', ['length' => 512, 'notnull' => false, 'default' => null]],
				'channel_mask' => ['integer', ['notnull' => true, 'default' => 1]],
				'quiet_start' => ['string', ['length' => 5, 'notnull' => false, 'default' => null]],
				'quiet_end' => ['string', ['length' => 5, 'notnull' => false, 'default' => null]],
				'quiet_timezone' => ['string', ['length' => 64, 'notnull' => true, 'default' => 'UTC']],
				'digest_mode' => ['string', ['length' => 8, 'notnull' => true, 'default' => 'instant']],
				'digest_time' => ['string', ['length' => 5, 'notnull' => true, 'default' => '08:00']],
				'expires_at' => ['bigint', ['notnull' => true, 'default' => 0]],
				'snoozed_until' => ['bigint', ['notnull' => true, 'default' => 0]],
				'excluded_folders' => ['string', ['length' => 1024, 'notnull' => false, 'default' => null]],
			]);
		}

		if ($schema->hasTable('abonnieren_mail_outbox')) {
			$outbox = $schema->getTable('abonnieren_mail_outbox');
			$this->addMissingColumns($outbox, [
				'batch_key' => ['string', ['length' => 64, 'notnull' => false, 'default' => null]],
				'batch_until' => ['bigint', ['notnull' => true, 'default' => 0]],
				'item_name' => ['string', ['length' => 255, 'notnull' => false, 'default' => null]],
				'quiet_start' => ['string', ['length' => 5, 'notnull' => false, 'default' => null]],
				'quiet_end' => ['string', ['length' => 5, 'notnull' => false, 'default' => null]],
				'quiet_timezone' => ['string', ['length' => 64, 'notnull' => true, 'default' => 'UTC']],
				'digest_key' => ['string', ['length' => 64, 'notnull' => false, 'default' => null]],
				'source_rule_id' => ['bigint', ['notnull' => true, 'default' => 0]],
				'source_rule_hash' => ['string', ['length' => 64, 'notnull' => false, 'default' => null]],
				'source_rule_expires_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			]);
			foreach ([
				'abonnieren_batch_idx' => ['batch_key', 'status', 'next_attempt_at'],
				'abonnieren_digest_idx' => ['digest_key', 'status', 'next_attempt_at'],
			] as $name => $columns) {
				if (!$outbox->hasIndex($name)) {
					$outbox->addIndex($columns, $name);
				}
			}
		}

		if (!$schema->hasTable('abonnieren_upload_scopes')) {
			$scope = $schema->createTable('abonnieren_upload_scopes');
			$scope->addColumn('scope_key', 'string', ['length' => 64, 'notnull' => true]);
			$scope->addColumn('batch_key', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
			$scope->addColumn('started_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$scope->addColumn('last_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$scope->addColumn('due_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$scope->addColumn('item_count', 'integer', ['notnull' => true, 'default' => 0]);
			$scope->setPrimaryKey(['scope_key']);
		}

		return $schema;
	}

	/** @param array<string,array{0:string,1:array<string,mixed>}> $columns */
	private function addMissingColumns(Table $table, array $columns): void {
		foreach ($columns as $name => [$type, $columnOptions]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, $columnOptions);
			}
		}
	}
}
