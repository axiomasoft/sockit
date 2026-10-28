<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

/** Frozen final-name snapshot for future DDL consumers; no live connection reference. */
final readonly class PhysicalSchema
{
    private string $descriptor;

    public string $digest;

    /** @param array<string, mixed> $descriptor
     * @param list<string> $relations */
    public function __construct(public StorageLocation $location, array $descriptor, public array $relations)
    {
        $this->descriptor = SchemaValues::json($descriptor);
        $this->digest = hash('sha256', $this->descriptor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = json_decode($this->descriptor, true, flags: JSON_THROW_ON_ERROR);

        return [...SchemaValues::map($data), 'digest' => $this->digest];
    }
}
