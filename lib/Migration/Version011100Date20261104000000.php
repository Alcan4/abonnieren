<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Create or complete the queued-mail schema in one idempotent migration. */
class Version011100Date20261104000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$created = !$schema->hasTable('abonnieren_mail_outbox');
		$table = $created
			? $schema->createTable('abonnieren_mail_outbox')
			: $schema->getTable('abonnieren_mail_outbox');

		$columns = [
			'id' => ['bigint', ['autoincrement' => true, 'notnull' => true]],
			'user_id' => ['string', ['length' => 64, 'notnull' => true]],
			'node_id' => ['bigint', ['notnull' => true]],
			'parent_id' => ['bigint', ['notnull' => false]],
			'event_mask' => ['integer', ['notnull' => true]],
			'actor_id' => ['string', ['length' => 64, 'notnull' => false]],
			'deleted' => ['boolean', ['notnull' => true, 'default' => false]],
			'subject' => ['string', ['length' => 255, 'notnull' => true]],
			'body_text' => ['text', ['notnull' => true]],
			'body_html' => ['text', ['notnull' => true]],
			'status' => ['string', ['length' => 16, 'notnull' => true, 'default' => 'pending']],
			'created_at' => ['bigint', ['notnull' => true]],
			'attempts' => ['integer', ['notnull' => true, 'default' => 0]],
			'next_attempt_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'lease_until' => ['bigint', ['notnull' => true, 'default' => 0]],
			'lease_token' => ['string', ['length' => 32, 'notnull' => false]],
			'updated_at' => ['bigint', ['notnull' => true, 'default' => 0]],
			'completed_at' => ['bigint', ['notnull' => true, 'default' => 0]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, $columnOptions);
			}
		}
		if ($created) {
			$table->setPrimaryKey(['id']);
		}
		if (!$table->hasIndex('abonnieren_outbox_state')) {
			$table->addIndex(['status', 'created_at'], 'abonnieren_outbox_state');
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
