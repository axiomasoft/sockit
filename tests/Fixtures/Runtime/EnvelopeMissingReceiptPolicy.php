<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\CommandNamespace;

final class EnvelopeMissingReceiptPolicy implements MissingReceiptPolicy
{
    /** @var array<string, string> */
    public array $actions = [EnvelopeMutation::TYPE => EnvelopeMutation::TYPE];

    public int $calls = 0;

    public function __construct(private readonly Application $app) {}

    public function decide(OperationHandle $handle, CommandNamespace $namespace): AuthorizationDecision
    {
        $this->calls++;
        $action = $this->actions[$namespace->commandType] ?? null;
        if ($action === null) {
            return AuthorizationDecision::unknown('fixture.command_action_unmapped');
        }

        return $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: $action, surface: $handle->surface));
    }
}
