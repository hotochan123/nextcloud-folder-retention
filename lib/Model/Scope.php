<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

enum Scope: string {
	/** „Auch Unterordner“ – Unterordner ohne eigene Regel erben */
	case Inherit = 'inherit';
	/** „Nur diese Ebene“ – gilt nur für Dateien direkt in diesem Ordner */
	case Here = 'here';
}
