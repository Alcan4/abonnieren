<?php
/**
 * Tests a real Nextcloud cron invocation without SMTP transport.
 *
 * From Nextcloud root:
 *   php apps/abonnieren/tests/Integration/cron_delivery_smoke.php seed /tmp/abo-cron-id
 *   php occ background:cron
 *   php -f cron.php
 *   php apps/abonnieren/tests/Integration/cron_delivery_smoke.php verify /tmp/abo-cron-id
 *
 * The target node is deliberately nonexistent, so real worker must cancel
 * and erase the payload without sending mail.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/base.php';

use OCA\Abonnieren\BackgroundJob\DeliverMailJob;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;

if (count($argv) !== 3 || !in_array($argv[1], ['seed', 'verify'], true)) {
	throw new InvalidArgumentException('Usage: script seed|verify marker-file');
}
$mode = $argv[1];
$file = $argv[2];
$db = \OC::$server->get(IDBConnection::class);
if ($mode === 'seed') {
	if (is_file($file)) {
		throw new RuntimeException('Marker already exists');
	}
	$now = time();
	$q = $db->getQueryBuilder();
	$q->insert('abonnieren_mail_outbox')->values([
		'user_id' => $q->createNamedParameter('admin'),
		'node_id' => $q->createNamedParameter(922337100),
		'event_mask' => $q->createNamedParameter(2),
		'actor_id' => $q->createNamedParameter('synthetic-editor'),
		'deleted' => $q->createNamedParameter(0),
		'subject' => $q->createNamedParameter('Synthetic cron check'),
		'body_text' => $q->createNamedParameter('Synthetic-only payload'),
		'body_html' => $q->createNamedParameter('<p>Synthetic-only payload</p>'),
		'status' => $q->createNamedParameter('pending'),
		'created_at' => $q->createNamedParameter($now),
		'updated_at' => $q->createNamedParameter($now),
		'next_attempt_at' => $q->createNamedParameter($now - 5),
	]);
	$q->executeStatement();
	$id = $db->lastInsertId('abonnieren_mail_outbox');
	file_put_contents($file, (string)$id, LOCK_EX);
	\OC::$server->get(IJobList::class)->add(DeliverMailJob::class, ['id' => $id]);
	echo "Seeded synthetic queued message ID: " . $id . "\n";
	exit(0);
}
if (!is_file($file)) {
	throw new RuntimeException('Missing marker from seed phase');
}
$id = (int)trim(file_get_contents($file));
try {
	$q = $db->getQueryBuilder();
	$q->select('status', 'subject', 'body_text', 'body_html', 'attempts')
		->from('abonnieren_mail_outbox')
		->where($q->expr()->eq('id', $q->createNamedParameter($id)));
	$result = $q->executeQuery();
	$row = $result->fetch();
	$result->closeCursor();
	if (!is_array($row)
		|| $row['status'] !== 'cancelled'
		|| $row['subject'] !== ''
		|| $row['body_text'] !== ''
		|| $row['body_html'] !== ''
		|| (int)$row['attempts'] !== 1) {
		throw new RuntimeException('Cron did not execute queued worker and scrub inaccessible message: '
			. json_encode(is_array($row) ? ['status' => $row['status'], 'attempts' => $row['attempts']] : null));
	}
	echo "PASS: real Nextcloud cron claimed, cancelled and scrubbed inaccessible mail\n";
} finally {
	$q = $db->getQueryBuilder();
	$q->delete('abonnieren_mail_outbox')
		->where($q->expr()->eq('id', $q->createNamedParameter($id)))
		->andWhere($q->expr()->eq('user_id', $q->createNamedParameter('admin')));
	$q->executeStatement();
	@unlink($file);
}
