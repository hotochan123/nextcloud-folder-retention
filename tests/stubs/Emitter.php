<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stellvertreter für die private Schnittstelle, von der OCP\Files\IRootFolder erbt –
 * nextcloud/ocp liefert sie nicht mit, ohne sie lässt sich IRootFolder nicht mocken.
 */
interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
