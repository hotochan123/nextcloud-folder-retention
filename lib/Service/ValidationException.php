<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use RuntimeException;

/** Invalid input – reported as HTTP 400 or as a command error. */
class ValidationException extends RuntimeException {
}
