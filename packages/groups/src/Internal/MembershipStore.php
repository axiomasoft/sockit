<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\Data\OperationHandle;

#[Internal]
final readonly class MembershipStore
{
    public function __construct(private Application $app) {}

    public function table(OperationHandle $handle): Builder
    {
        $storage = $handle->panel->storage;
        $table = ($storage->schema === null ? '' : $storage->schema.'.').($storage->tables['group_members'] ?? throw new GroupException(ProblemCode::ConfigurationInvalid));

        return $this->app->make(ConnectionResolverInterface::class)->connection($storage->connection)->table($table);
    }

    /** Owning group must already be verified, and locked for a mutation.
     * @return array<string, mixed>|null */
    public function row(OperationHandle $handle, MemberIdentity $identity, bool $lock = false): ?array
    {
        $query = $this->table($handle)->where('group_id', $identity->columns()['group_id'])->where('actor_digest', $identity->digest());
        $raw = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($raw === null) {
            return null;
        }
        $row = [];
        foreach (get_object_vars($raw) as $key => $value) {
            if (! is_string($key)) {
                throw new GroupException(ProblemCode::PayloadInvalid);
            }
            $row[$key] = $value;
        }
        $identity->verify($row);

        return $row;
    }
}
