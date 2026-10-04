<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCP\IL10N;
use Throwable;

/**
 * Translator for texts the app stores instance-wide (tag names, log texts, lock reasons), see
 * ContentLanguage. Deliberately not Nextcloud's IFactory::get(): that one lets the request
 * parameter `forceLanguage`, the system config `force_language` and an unsupported language code
 * silently switch to another language, and a tag name in the wrong language is a new system tag.
 *
 * Reads only the app's own l10n/<lang>.json. Never throws while rendering: a translation with a
 * broken placeholder falls back to the source text, so a bad translation file cannot abort the
 * deletion path between moving a file to the trash bin and logging it. Knows two plural forms
 * (n == 1, everything else); ContentLanguage only accepts languages with exactly that rule.
 */
class FixedL10N implements IL10N {
	/**
	 * @param array<string, string|list<string>> $translations
	 * @param IL10N $formatter only for l() (dates, numbers) – never used for stored texts
	 */
	public function __construct(
		private string $lang,
		private array $translations,
		private IL10N $formatter,
	) {
	}

	public function t(string $text, $parameters = []): string {
		$translated = $this->translations[$text] ?? null;
		return $this->render(is_string($translated) ? $translated : $text, $text, is_array($parameters) ? $parameters : [$parameters]);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		$source = $count === 1 ? $text_singular : $text_plural;
		$forms = $this->translations['_' . $text_singular . '_::_' . $text_plural . '_'] ?? null;
		$translated = is_array($forms) ? ($forms[$count === 1 ? 0 : 1] ?? null) : null;
		$render = fn (string $text): string => str_replace('%n', (string)$count, $text);
		return $this->render(is_string($translated) ? $render($translated) : $render($source), $render($source), $parameters);
	}

	/** @param array<mixed> $parameters */
	private function render(string $text, string $source, array $parameters): string {
		foreach ([$text, $source] as $candidate) {
			try {
				return vsprintf($candidate, array_map(fn ($p) => is_scalar($p) || $p instanceof \Stringable ? (string)$p : json_encode($p), $parameters));
			} catch (Throwable) {
			}
		}
		return $source . ($parameters === [] ? '' : ' ' . json_encode(array_values($parameters), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}

	public function l(string $type, $data, array $options = []) {
		return $this->formatter->l($type, $data, $options);
	}

	public function getLanguageCode(): string {
		return $this->lang;
	}

	public function getLocaleCode(): string {
		return $this->formatter->getLocaleCode();
	}
}
