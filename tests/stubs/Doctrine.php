<?php

declare(strict_types=1);

/**
 * Stellvertreter für die Doctrine-Konstanten, auf die OCP\DB\QueryBuilder\IQueryBuilder und
 * IExpressionBuilder verweisen – nextcloud/ocp liefert Doctrine nicht mit, ohne sie lassen sich
 * IDBConnection und IQueryBuilder nicht mocken. Werte wie doctrine/dbal 3.x (NC 34).
 */

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class)) {
		final class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BOOLEAN = 5;
			public const BINARY = 16;
			public const ASCII = 17;
		}
	}
	if (!class_exists(ArrayParameterType::class)) {
		final class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
			public const ASCII = 117;
			public const BINARY = 116;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class)) {
		final class Types {
			public const BOOLEAN = 'boolean';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const TIME_MUTABLE = 'time';
		}
	}
}

namespace Doctrine\DBAL\Query\Expression {
	if (!class_exists(ExpressionBuilder::class)) {
		final class ExpressionBuilder {
			public const EQ = '=';
			public const NEQ = '<>';
			public const LT = '<';
			public const LTE = '<=';
			public const GT = '>';
			public const GTE = '>=';
		}
	}
}
