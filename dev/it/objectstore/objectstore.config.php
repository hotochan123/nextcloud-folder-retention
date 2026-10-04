<?php
// Nur für den Harness (dev/it, S23): Primärspeicher = Objektspeicher (FretDirObjectStore)
$CONFIG = [
	'objectstore' => [
		'class' => 'OC\\Files\\ObjectStore\\FretDirObjectStore',
		'arguments' => [
			'dir' => '/var/www/html/data/objects',
		],
	],
];
