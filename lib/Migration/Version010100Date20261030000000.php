<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store rendered outgoing notifications without exposing mail bodies in job arguments. */
class Version010100Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abonnieren_mail_outbox')) {
			$table = $schema->createTable('abonnieren_mail_outbox');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('node_id', 'bigint', ['notnull' => true]);
			$table->addColumn('parent_id', 'bigint', ['notnull' => false]);
			$table->addColumn('event_mask', 'integer', ['notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 64, 'notnull' => false]);
			$table->addColumn('deleted', 'boolean', ['notnull' => true, 'default' => false]);
			$table->addColumn('subject', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('body_text', 'text', ['notnull' => true]);
			$table->addColumn('body_html', 'text', ['notnull' => true]);
			$table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$table->addColumn('created_at', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status', 'created_at'], 'abonnieren_outbox_state');
		}

		return $schema;
	}
}
