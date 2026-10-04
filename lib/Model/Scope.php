<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

enum Scope: string {
	/** "Subfolders too" – subfolders without their own rule inherit */
	case Inherit = 'inherit';
	/** "This level only" – applies only to files directly in this folder */
	case Here = 'here';
}
