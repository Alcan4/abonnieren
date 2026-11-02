<?php
/**
 * Real SMTP capture smoke test. Use only in a disposable Nextcloud instance
 * whose SMTP server points to the Mailpit service container.
 *
 * php apps/abonnieren/tests/Integration/smtp_capture_smoke.php seed /tmp/abo-smtp.json
 * php occ background-job:worker --once 'OCA\Abonnieren\BackgroundJob\DeliverMailJob'
 * php apps/abonnieren/tests/Integration/smtp_capture_smoke.php verify /tmp/abo-smtp.json
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/base.php';

use OCA\Abonnieren\AppInfo\Application;
use OCA\Abonnieren\Service\MailOutbox;
use OCA\Abonnieren\Service\SubscriptionService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;

if (count($argv) !== 3 || !in_array($argv[1], ['seed', 'verify'], true)) {
	throw new InvalidArgumentException('Expected seed|verify and a temporary marker file');
}
$mode = $argv[1];
$marker = $argv[2];
$db = \OC::$server->get(IDBConnection::class);
$root = \OC::$server->get(IRootFolder::class);
$app = new Application();
$service = $app->getContainer()->get(SubscriptionService::class);
if ($mode === 'seed') {
	if (is_file($marker)) {
		throw new RuntimeException('Marker must not exist before setup');
	}
	$user = \OC::$server->get(IUserManager::class)->get('admin');
	if ($user === null) {
		throw new RuntimeException('Disposable admin account missing');
	}
	$user->setEMailAddress('ci-recipient@example.test');
	$folder = $root->getUserFolder('admin');
	$name = 'abo-ci-smtp-' . bin2hex(random_bytes(6)) . '.txt';
	$node = $folder->newFile($name, 'Synthetic integration data');
	$nodeId = (int)$node->getId();
	$service->upsertRule(
		'admin', $nodeId, SubscriptionService::EVENT_MODIFICATION,
		false, 0, '', SubscriptionService::CHANNEL_EMAIL,
	);
	$subject = 'Abonnieren SMTP capture ' . bin2hex(random_bytes(6));
	$outbox = $app->getContainer()->get(MailOutbox::class);
	$outbox->enqueue(
		'admin', $node, SubscriptionService::EVENT_MODIFICATION,
		'synthetic-editor', false, $subject,
		'Synthetic notification body', '<p>Synthetic notification body</p>',
	);
	$q = $db->getQueryBuilder();
	$q->select('id', 'status')->from('abonnieren_mail_outbox')
		->where($q->expr()->eq('subject', $q->createNamedParameter($subject)))
		->setMaxResults(1);
	$r = $q->executeQuery();
	$row = $r->fetch();
	$r->closeCursor();
	if (!is_array($row) || $row['status'] !== 'pending') {
		throw new RuntimeException('SMTP test message was not queued');
	}
	file_put_contents($marker, json_encode([
		'id' => (int)$row['id'], 'nodeId' => $nodeId, 'subject' => $subject,
	], JSON_THROW_ON_ERROR), LOCK_EX);
	echo "SMTP test message queued in real outbox\n";
	exit(0);
}
if (!is_file($marker)) {
	throw new RuntimeException('Missing SMTP seed marker');
}
$meta = json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
$id = (int)$meta['id'];
try {
	$q = $db->getQueryBuilder();
	$q->select('status', 'attempts', 'body_text', 'body_html')
		->from('abonnieren_mail_outbox')
		->where($q->expr()->eq('id', $q->createNamedParameter($id)));
	$r = $q->executeQuery();
	$row = $r->fetch();
	$r->closeCursor();
	if (!is_array($row) || $row['status'] !== 'sent'
		|| (int)$row['attempts'] !== 1
		|| $row['body_text'] !== '' || $row['body_html'] !== '') {
		throw new RuntimeException('Real SMTP worker did not finish and scrub message: '
			. json_encode(is_array($row) ? ['status' => $row['status'], 'attempts' => $row['attempts']] : null));
	}
	echo "PASS: Nextcloud mail worker sent SMTP and scrubbed outbox body\n";
} finally {
	$q = $db->getQueryBuilder();
	$q->delete('abonnieren_mail_outbox')
		->where($q->expr()->eq('id', $q->createNamedParameter($id)));
	$q->executeStatement();
	$service->deleteRule('admin', (int)$meta['nodeId']);
	$nodes = $root->getUserFolder('admin')->getById((int)$meta['nodeId']);
	if ($nodes !== []) {
		$nodes[0]->delete();
	}
	@unlink($marker);
}
