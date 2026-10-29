<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Support\Ids;

/** Persistence extension only. An ORM instance carries no domain authorization. */
#[Spi(kind: SpiKind::Extend)]
abstract class PanelModel extends Model
{
    /** @var array<string, mixed> Frozen consumer-owned binding. */
    public const array BINDING = [];

    /** @param array<string, mixed> $attributes */
    final public function __construct(array $attributes = [])
    {
        $binding = static::binding();
        $this->keyType = SchemaValues::text($binding['key_type']);
        $this->incrementing = $binding['incrementing'] === true;
        $this->timestamps = $binding['timestamps'] === true;
        $this->guarded = [];
        parent::__construct($attributes);
    }

    /** @return array<string, mixed> */
    final public static function binding(): array
    {
        SchemaValues::require(valid: static::BINDING !== [], key: 'model', rule: 'MODEL_BINDING_MISSING');

        return static::BINDING;
    }

    final public function getTable(): string
    {
        $binding = static::binding();

        return ($binding['schema'] === null ? '' : SchemaValues::text($binding['schema']).'.').SchemaValues::text($binding['table']);
    }

    final public function getConnectionName(): string
    {
        return SchemaValues::text(static::binding()['connection']);
    }

    /** Laravel prefixes the first segment of qualified columns, unlike schema-qualified FROM.
     * @param string $column */
    final public function qualifyColumn($column): string
    {
        return str_contains($column, '.') ? $column : SchemaValues::text(static::binding()['table']).'.'.$column;
    }

    /** @return $this */
    final public function setTable($table)
    {
        SchemaValues::require(valid: $table === $this->getTable(), key: 'table', rule: 'MODEL_BINDING_INVALID');

        return $this;
    }

    /** @return $this */
    final public function setConnection($name)
    {
        SchemaValues::require(valid: $name === $this->getConnectionName(), key: 'connection', rule: 'MODEL_BINDING_INVALID');

        return $this;
    }

    final public function getKeyName(): string
    {
        $this->assertSingleKey();
        $primary = static::binding()['primary'];
        assert(is_array($primary));

        return SchemaValues::text($primary[0]);
    }

    final public function getKeyType(): string
    {
        return SchemaValues::text(static::binding()['key_type']);
    }

    final public function getIncrementing(): bool
    {
        return static::binding()['incrementing'] === true;
    }

    /** @return $this */
    final public function setKeyName($key)
    {
        SchemaValues::require(valid: $key === $this->getKeyName(), key: 'primary', rule: 'MODEL_BINDING_INVALID');

        return $this;
    }

    /** @return $this */
    final public function setKeyType($type)
    {
        SchemaValues::require(valid: $type === $this->getKeyType(), key: 'key_type', rule: 'MODEL_BINDING_INVALID');

        return $this;
    }

    /** @return $this */
    final public function setIncrementing($value)
    {
        SchemaValues::require(valid: $value === $this->getIncrementing(), key: 'incrementing', rule: 'MODEL_BINDING_INVALID');

        return $this;
    }

    /** @return array<string, string> */
    final public function getCasts(): array
    {
        $casts = static::binding()['casts'];
        assert(is_array($casts));

        /** @var array<string, string> $casts */
        return $casts;
    }

    final public function usesUniqueIds(): bool
    {
        return static::binding()['key_policy'] === 'uuid7';
    }

    /** @return list<string> */
    final public function uniqueIds(): array
    {
        return $this->usesUniqueIds() ? [$this->getKeyName()] : [];
    }

    final public function newUniqueId(): string
    {
        return Ids::uuid7();
    }

    /** @return bool */
    final public function save(array $options = [])
    {
        $this->assertSingleKey();

        return parent::save($options);
    }

    /** @return bool|null */
    final public function delete()
    {
        $this->assertSingleKey();

        return parent::delete();
    }

    final protected function newBaseQueryBuilder(): Builder
    {
        $this->assertSingleKey();

        return parent::newBaseQueryBuilder();
    }

    /** @return mixed */
    final public function getQueueableId()
    {
        $this->assertSingleKey();

        return parent::getQueueableId();
    }

    /** Composite entities expose only exact, full-identity reads; no fake single ID.
     * @param array<string, string|int> $identity
     * @return array<string, mixed>|null */
    final public static function readIdentity(array $identity): ?array
    {
        $model = new static;
        $binding = static::binding();
        $raw = $binding['primary'];
        SchemaValues::require(valid: is_array($raw), key: 'primary');
        $primary = array_map(SchemaValues::text(...), $raw);
        SchemaValues::require(valid: $binding['key_policy'] === 'composite' && count($identity) === count($primary) && array_diff($primary, array_keys($identity)) === [], key: 'identity', rule: 'MODEL_COMPOSITE_IDENTITY_REQUIRED');
        SchemaValues::require(valid: ! isset($identity['panel']) || $identity['panel'] === $binding['panel'], key: 'panel', rule: 'MODEL_BINDING_INVALID');
        $row = $model->getConnection()->table($model->getTable())->where($identity)->first();

        return $row === null ? null : SchemaValues::map(get_object_vars($row));
    }

    private function assertSingleKey(): void
    {
        SchemaValues::require(valid: static::binding()['key_policy'] !== 'composite', key: 'primary', rule: 'MODEL_COMPOSITE_READ_ONLY');
    }
}
