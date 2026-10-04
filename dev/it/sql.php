<?php
// Kleiner SQLite-Helfer für den Integrations-Harness (läuft IM Wegwerf-Container).
// Aufruf: php sql.php "SELECT … WHERE x = ?" [param …]
// Ausgabe: eine Zeile je Ergebniszeile, Spalten mit Tab getrennt, NULL als leere Zeichenkette.
declare(strict_types=1);

$db = new PDO('sqlite:' . (getenv('FRET_DB') ?: '/var/www/html/data/nextcloud.db'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Nextcloud schreibt parallel (Job, WebDAV) – lieber warten als „database is locked“
$db->setAttribute(PDO::ATTR_TIMEOUT, 60);

$stmt = $db->prepare($argv[1] ?? '');
$stmt->execute(array_slice($argv, 2));
while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
	echo implode("\t", array_map(fn ($v) => $v === null ? '' : (string)$v, $row)), "\n";
}
