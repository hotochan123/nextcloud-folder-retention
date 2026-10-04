<?php
// Small SQLite helper for the integration harness (runs INSIDE the throwaway container).
// Usage: php sql.php "SELECT … WHERE x = ?" [param …]
// Output: one line per result row, columns separated by tabs, NULL as an empty string.
declare(strict_types=1);

$db = new PDO('sqlite:' . (getenv('FRET_DB') ?: '/var/www/html/data/nextcloud.db'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Nextcloud writes in parallel (job, WebDAV) – better to wait than get "database is locked"
$db->setAttribute(PDO::ATTR_TIMEOUT, 60);

$stmt = $db->prepare($argv[1] ?? '');
$stmt->execute(array_slice($argv, 2));
while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
	echo implode("\t", array_map(fn ($v) => $v === null ? '' : (string)$v, $row)), "\n";
}
