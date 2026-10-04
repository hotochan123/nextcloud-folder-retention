<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use InvalidArgumentException;
use OCA\FolderRetention\Model\Resolution;
use OCA\FolderRetention\Model\RetentionRule;

/**
 * Reine Auflösungslogik – kein Dateisystem, keine DB.
 *
 * Für eine Datei F mit direktem Elternordner P:
 *  1. Hat P eine Regel, gilt sie – unabhängig vom Geltungsbereich.
 *  2. Sonst aufwärts: der erste Vorfahr mit Regel UND scope=inherit gewinnt;
 *     Regeln mit scope=here werden übersprungen.
 *  3. Sonst gilt die Standardregel.
 *
 * Dieselbe Funktion liefert die „effektive Regel eines Ordners“ für die UI:
 * Das ist die Regel, die für Dateien direkt in diesem Ordner gilt, also
 * resolve([ordner, eltern, großeltern, …]).
 */
class RuleResolver {

	/**
	 * @param list<int> $folderChain Ordner-IDs vom direkten Elternordner der Datei aufwärts
	 *                               bis zur Wurzel, z. B. [P, P.parent, …]
	 * @param array<int, RetentionRule> $rulesByFolderId Ordnerregeln, Schlüssel = folderId
	 */
	public function resolve(array $folderChain, array $rulesByFolderId, RetentionRule $default): Resolution {
		return $this->walk($folderChain, $rulesByFolderId, $default, true);
	}

	/**
	 * Regel, die ein (hypothetischer) Unterordner OHNE eigene Regel von $folderChain[0]
	 * erben würde. Für die UI-Zeile „Unterordner erben von …“ bei scope=here.
	 * Die Tiefe im Ergebnis ist relativ zu $folderChain[0] (0 = dieser Ordner).
	 *
	 * @param list<int> $folderChain
	 * @param array<int, RetentionRule> $rulesByFolderId
	 */
	public function resolveForChildren(array $folderChain, array $rulesByFolderId, RetentionRule $default): Resolution {
		return $this->walk($folderChain, $rulesByFolderId, $default, false);
	}

	/**
	 * @param list<int> $folderChain
	 * @param array<int, RetentionRule> $rulesByFolderId
	 */
	private function walk(array $folderChain, array $rulesByFolderId, RetentionRule $default, bool $directParentAnyScope): Resolution {
		if (!$default->isDefault()) {
			throw new InvalidArgumentException('Default rule expected (folderId = null)');
		}

		foreach (array_values($folderChain) as $depth => $folderId) {
			$rule = $rulesByFolderId[$folderId] ?? null;
			if ($rule === null) {
				continue;
			}
			if ($rule->folderId !== $folderId) {
				throw new InvalidArgumentException("Rule under key $folderId belongs to folder {$rule->folderId}");
			}
			if (($depth === 0 && $directParentAnyScope) || $rule->inherits()) {
				return new Resolution($rule, $depth);
			}
			// scope=here an einem Vorfahren: überspringen
		}

		return new Resolution($default, null);
	}
}
