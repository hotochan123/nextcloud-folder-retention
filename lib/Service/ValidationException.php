<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use RuntimeException;

/** Ungültige Eingabe – wird als HTTP 400 bzw. Kommando-Fehler ausgegeben. */
class ValidationException extends RuntimeException {
}
