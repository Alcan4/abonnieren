<?php
/**
 * Disposable multi-process Nextcloud database contention test.
 *
 * From the Nextcloud root:
 *   php apps/abonnieren/tests/Integration/concurrent_upload_smoke.php
 * No real files or email accounts are accessed. Exit 1 means a concurrency invariant failed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/base.php';

use OCA\Abonnieren\Service\UploadBatch;
use OCA\Abonnieren\Service\UploadBatchCoordinator;
use OCP\IDBConnection;

$db = \OC::$server->get(IDBConnection::class);
$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
	[$script, $mode, $uid, $actor, $folderId, $timestamp, $serial, $gate] = $argv;
	$deadline = microtime(true) + 20;
	while (!is_file($gate) && microtime(true) < $deadline) {
		usleep(20000);
	}
	if (!is_file($gate)) {
		throw new RuntimeException('Concurrent worker start signal never arrived');
	}
	$now = (int)$timestamp;
	$coordinator = new UploadBatchCoordinator($db);
	$result = $coordinator->add($uid, $actor, (int)$folderId, $now,
		static function (string $key, int $due) use ($db, $uid, $serial, $now): int {
			$q = $db->getQueryBuilder();
			$q->insert('abonnieren_mail_outbox')->values([
				'user_id' => $q->createNamedParameter($uid),
				'node_id' => $q->createNamedParameter(870000 + (int)$serial),
				'event_mask' => $q->createNamedParameter(1),
				'deleted' => $q->createNamedParameter(0),
				'subject' => $q->createNamedParameter('Synthetic upload'),
				'body_text' => $q->createNamedParameter('Synthetic content'),
				'body_html' => $q->createNamedParameter('<p>Synthetic content</p>'),
				'status' => $q->createNamedParameter('pending'),
				'created_at' => $q->createNamedParameter($now),
				'updated_at' => $q->createNamedParameter($now),
				'next_attempt_at' => $q->createNamedParameter($due),
				'batch_key' => $q->createNamedParameter($key),
				'batch_until' => $q->createNamedParameter($due),
				'item_name' => $q->createNamedParameter('synthetic-' . $serial),
			]);
			$q->executeStatement();
			return $db->lastInsertId('abonnieren_mail_outbox');
		},
	);
	echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
	exit(0);
}

if ($mode !== 'orchestrate') {
	throw new InvalidArgumentException('Unexpected smoke test mode');
}
if (!function_exists('proc_open')) {
	throw new RuntimeException('Concurrent smoke test requires proc_open()');
}
$uid = 'abo_concurrent_' . bin2hex(random_bytes(5));
$actor = 'synthetic_uploader';
$folder = random_int(1000000, 2000000);
$now = time();
$scopeKey = UploadBatch::scopeKey($uid, $actor, $folder);
$gate = sys_get_temp_dir() . '/abo_batch_gate_' . bin2hex(random_bytes(8));
$workers = [];
try {
	for ($i = 0; $i < 6; $i++) {
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
			. ' worker ' . escapeshellarg($uid)
			. ' ' . escapeshellarg($actor)
			. ' ' . escapeshellarg((string)$folder)
			. ' ' . escapeshellarg((string)$now)
			. ' ' . escapeshellarg((string)$i)
			. ' ' . escapeshellarg($gate);
		$pipes = [];
		$process = proc_open($command, [
			0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
		], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start concurrent worker');
		}
		fclose($pipes[0]);
		$workers[] = [$process, $pipes];
	}
	touch($gate);
	$ids = [];
	foreach ($workers as [$process, $pipes]) {
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) {
			throw new RuntimeException('Parallel DB worker failed: ' . substr($error, 0, 1800));
		}
		$record = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		$ids[] = (int)$record['id'];
		if ((int)$record['due'] !== $now + UploadBatch::QUIET_SECONDS) {
			throw new RuntimeException('Concurrent worker received an inconsistent due time');
		}
	}
	if (count(array_unique($ids)) !== 6) {
		throw new RuntimeException('Concurrent workers did not persist six unique messages');
	}
	$q = $db->getQueryBuilder();
	$q->select('id', 'status', 'batch_key', 'batch_until', 'next_attempt_at')
		->from('abonnieren_mail_outbox')
		->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
	$result = $q->executeQuery();
	$rows = $result->fetchAll();
	$result->closeCursor();
	if (count($rows) !== 6) {
		throw new RuntimeException('Expected 6 committed rows, got ' . count($rows));
	}
	foreach ($rows as $row) {
		if ($row['status'] !== 'pending'
			|| (int)$row['batch_until'] !== $now + 90
			|| (int)$row['next_attempt_at'] !== $now + 90
			|| strlen((string)$row['batch_key']) !== 64) {
			throw new RuntimeException('Inconsistent pending batch after simultaneous writes');
		}
	}
	if (count(array_unique(array_column($rows, 'batch_key'))) !== 1) {
		throw new RuntimeException('Parallel uploaders split one burst into multiple batches');
	}
	$q = $db->getQueryBuilder();
	$q->select('item_count')->from('abonnieren_upload_scopes')
		->where($q->expr()->eq('scope_key', $q->createNamedParameter($scopeKey)));
	$result = $q->executeQuery();
	$scope = $result->fetch();
	$result->closeCursor();
	if ((int)($scope['item_count'] ?? -1) !== 6) {
		throw new RuntimeException('Batch item count lost concurrent updates');
	}
	echo "PASS: 6 parallel uploaders, 6 committed rows, one group, one deadline\n";
} finally {
	@unlink($gate);
	$q = $db->getQueryBuilder();
	$q->delete('abonnieren_mail_outbox')
		->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
	$q->executeStatement();
	$q = $db->getQueryBuilder();
	$q->delete('abonnieren_upload_scopes')
		->where($q->expr()->eq('scope_key', $q->createNamedParameter($scopeKey)));
	$q->executeStatement();
}
