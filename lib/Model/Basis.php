<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

enum Basis: string {
	case Created = 'created';
	case Modified = 'modified';
}
