<?php

declare(strict_types=1);

namespace SocKit\Panels\Data;

use Packman\Attributes\Api;
use Packman\Schema\Data\Compiler\ConstraintNames;
use SocKit\Panels\Exceptions\PanelException;

/**
 * Physical storage isolation descriptor for a compiled panel (connection, optional SQL schema, prefix, tables, models).
 */
#[Api(since: '1.0')]
final readonly class PanelStorage
{
    public const string SQL_IDENTIFIER_PATTERN = '/\A[a-z_][a-z0-9_]*\z/';

    /**
     * @param  array<string, string>  $tables  logical table => physical table override
     * @param  array<string, class-string>  $models  logical entity => concrete model FQCN
     */
    public function __construct(
        public string $connection = '',
        public ?string $schema = null,
        public string $prefix = 'sk_',
        public array $tables = [],
        public array $models = [],
        public string $ids = 'uuid7',
    ) {
        if ($schema !== null && (preg_match(self::SQL_IDENTIFIER_PATTERN, $schema) !== 1 || strlen($schema) > ConstraintNames::MAX_IDENTIFIER)) {
            throw PanelException::invalidConfig('PANEL_TABLE_INVALID', "Invalid panel SQL schema [{$schema}].", ['schema' => $schema]);
        }

        if ($prefix !== '' && preg_match('/\A[a-z][a-z0-9_]{0,15}_\z/', $prefix) !== 1) {
            throw PanelException::invalidConfig('PANEL_TABLE_INVALID', "Invalid panel table prefix [{$prefix}].", ['prefix' => $prefix]);
        }

        foreach ($tables as $logical => $physical) {
            if (preg_match(self::SQL_IDENTIFIER_PATTERN, $logical) !== 1) {
                throw PanelException::invalidConfig('PANEL_TABLE_INVALID', "Invalid logical table key [{$logical}].", ['table' => $logical]);
            }

            if (preg_match(self::SQL_IDENTIFIER_PATTERN, $physical) !== 1 || strlen($physical) > ConstraintNames::MAX_IDENTIFIER) {
                throw PanelException::invalidConfig('PANEL_TABLE_INVALID', "Invalid or >63-byte physical table [{$physical}].", ['table' => $physical]);
            }
        }

        if ($ids !== 'uuid7') {
            throw PanelException::invalidConfig('PANEL_TABLE_INVALID', "Unsupported panel ID strategy [{$ids}].", ['ids' => $ids]);
        }
    }

    public function resolveTable(string $logical): string
    {
        $resolved = $this->tables[$logical] ?? ($this->prefix.$logical);

        if (preg_match(self::SQL_IDENTIFIER_PATTERN, $resolved) !== 1 || strlen($resolved) > ConstraintNames::MAX_IDENTIFIER) {
            throw PanelException::invalidConfig(
                'PANEL_TABLE_INVALID',
                "Resolved physical table [{$resolved}] exceeds 63-byte PostgreSQL limit or contains invalid characters.",
                ['logical' => $logical, 'physical' => $resolved],
            );
        }

        return $resolved;
    }
}
