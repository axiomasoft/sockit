<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Database\Connection;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;

final class MediaAdapter implements MediaResolver
{
    /** @var list<int> */
    public array $levels = [];

    /** @var list<MediaReference> */
    public array $references = [];

    public function __construct(private readonly Connection $connection, public string $decision = 'allow') {}

    public function associate(OperationHandle $handle, MediaReference $media): AuthorizationDecision
    {
        $this->levels[] = $this->connection->transactionLevel();
        $this->references[] = $media;
        if ($this->connection->transactionLevel() !== 0 || $this->decision === 'error') {
            throw new \RuntimeException('Media unavailable or called under lock');
        }

        return match ($this->decision) {
            'allow' => AuthorizationDecision::allow('host.visible_association'),
            'deny' => AuthorizationDecision::deny('host.media_not_visible'),
            default => AuthorizationDecision::unknown('host.media_unknown'),
        };
    }
}
