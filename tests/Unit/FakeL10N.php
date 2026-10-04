<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit;

use OCP\IL10N;

/**
 * IL10N for unit tests, mimicking Nextcloud's L10N/L10NString: "en" returns the source
 * strings, "de" uses the PHP translation fragment l10n/.php-de.json (so tests asserting German
 * texts also prove the translations are byte-identical to the former hard-coded German).
 * Missing German translations fail loudly instead of silently falling back to English.
 */
class FakeL10N implements IL10N {
	/** @var array<string, string|list<string>> */
	private array $translations = [];

	public function __construct(
		private string $lang = 'de',
		private bool $strict = true,
	) {
		if ($lang === 'de') {
			$json = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/.php-de.json'), true, 512, JSON_THROW_ON_ERROR);
			$this->translations = $json['translations'];
		}
	}

	public static function de(): self {
		return new self('de');
	}

	public static function en(): self {
		return new self('en');
	}

	public function t(string $text, $parameters = []): string {
		return $this->render($text, is_array($parameters) ? $parameters : [$parameters], null, $text);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		$key = "_{$text_singular}_::_{$text_plural}_";
		return $this->render($key, $parameters, $count, $count === 1 ? $text_singular : $text_plural);
	}

	private function render(string $key, array $parameters, ?int $count, string $source): string {
		if ($this->lang === 'en') {
			$text = $source;
		} elseif (array_key_exists($key, $this->translations)) {
			$text = $this->translations[$key];
			if (is_array($text)) {
				$text = $text[$count === 1 ? 0 : 1];
			}
		} elseif ($this->strict) {
			throw new \LogicException('Missing German translation for ' . json_encode($key, JSON_UNESCAPED_UNICODE));
		} else {
			$text = $source;
		}
		if (str_contains($text, '|')) {
			throw new \LogicException('Nextcloud cannot render "|" in translations: ' . $text);
		}
		if ($count !== null) {
			$text = str_replace('%n', (string)$count, $text);
		}
		return vsprintf($text, $parameters);
	}

	public function l(string $type, $data, array $options = []) {
		return (string)$data;
	}

	public function getLanguageCode(): string {
		return $this->lang;
	}

	public function getLocaleCode(): string {
		return $this->lang;
	}
}
