<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Modules\Modules;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

/** Only FQCN declarations live in the singleton, never scoped resolver/actor/installation instances. */
#[Internal]
final class TargetCapabilities
{
    /** @var array<string, array<string, class-string<TargetResolver>>>|null */
    private ?array $draft = null;

    /** @var array<string, array<string, class-string<TargetResolver>>>|null */
    private ?array $published = null;

    public function compile(Modules $modules, PanelDefinitions $panels): void
    {
        if ($this->draft !== null || $this->published !== null) {
            $this->phaseError();
        }
        $map = [];
        foreach ($panels->all() as $id => $panel) {
            $map[$id] = [];
            foreach ($panel->modules as $module) {
                foreach ($modules->declarations()[$module]['targets'] as $alias => $resolver) {
                    if (isset($map[$id][$alias])) {
                        throw new InvalidConfigurationException(message: 'Duplicate target capability in panel', detail: ['panel' => $id, 'key' => 'targets.'.$alias, 'rule' => 'MODULE_TARGET_DUPLICATE']);
                    }
                    $map[$id][$alias] = $resolver;
                }
            }
            ksort($map[$id]);
        }
        $this->draft = $map;
    }

    public function publish(): void
    {
        if ($this->draft === null || $this->published !== null) {
            $this->phaseError();
        }
        $this->published = $this->draft;
    }

    public function resolve(Application $app, OperationHandle $handle, TargetRef $target): TargetResolution
    {
        return $this->resolver(app: $app, handle: $handle, target: $target)->resolve(actor: $handle->actor, surface: $handle->surface, target: $target);
    }

    public function recheck(Application $app, OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $resolver = $this->resolver(app: $app, handle: $handle, target: $resolved->target);
        if (! $resolver instanceof TargetWriteFence) {
            return AuthorizationDecision::unknown('target.local_fence_unavailable');
        }

        return $resolver->recheck(handle: $handle, resolved: $resolved);
    }

    private function resolver(Application $app, OperationHandle $handle, TargetRef $target): TargetResolver
    {
        if ($this->published === null) {
            $this->phaseError();
        }
        $fqcn = $this->published[$handle->surface->panel->value][$target->host->type] ?? null;
        if ($fqcn === null && ! in_array($target->host->type, $handle->panel->hostTypes, true)) {
            throw new ProviderUnavailableException(message: 'Target capability is unavailable on this panel', detail: ['key' => 'targets.'.$target->host->type]);
        }
        // Host fallback is an explicit adapter binding. Unknown/disabled module aliases never reach it.
        $instance = $app->make($fqcn ?? TargetResolver::class);
        if (! $instance instanceof TargetResolver) {
            throw new ProviderUnavailableException(message: 'Target resolver binding is unavailable', detail: ['key' => 'targets.'.$target->host->type]);
        }

        return $instance;
    }

    private function phaseError(): never
    {
        throw new InvalidConfigurationException(message: 'Target capabilities are not available in this phase', detail: ['key' => 'targets', 'rule' => 'TARGET_REGISTRY_PHASE']);
    }
}
