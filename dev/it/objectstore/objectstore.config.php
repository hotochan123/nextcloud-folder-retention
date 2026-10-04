<?php
// Harness only (dev/it, S23): primary storage = object store (FretDirObjectStore)
$CONFIG = [
	'objectstore' => [
		'class' => 'OC\\Files\\ObjectStore\\FretDirObjectStore',
		'arguments' => [
			'dir' => '/var/www/html/data/objects',
		],
	],
];
