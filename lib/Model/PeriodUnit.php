<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

enum PeriodUnit: string {
	case Day = 'day';
	case Week = 'week';
	case Month = 'month';
	case Never = 'never';
}
