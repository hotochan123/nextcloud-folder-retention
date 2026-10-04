<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stand-in for the private interface that OCP\Files\IRootFolder extends –
 * nextcloud/ocp does not ship it, and without it IRootFolder cannot be mocked.
 */
interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
