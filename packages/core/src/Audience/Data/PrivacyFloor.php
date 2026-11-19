<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Audience\Exceptions\InvalidPrivacyFloorException;

#[Api(since: '1.0')]
final readonly class PrivacyFloor
{
    /**
     * @param  array<Scope>  $allowedScopes
     * @param  array<string>  $contourLevels  Ordered narrow to broad.
     */
    public function __construct(
        public array $allowedScopes,
        public Scope $defaultScope,
        public Scope $maxScope,
        public Scope $minorDefaultScope,
        public Scope $minorMaxScope,
        public array $contourLevels,
    ) {
        $this->requireRule(valid: array_is_list($allowedScopes) && $allowedScopes !== [] && count(array_unique(array_map(fn (Scope $s): string => $s->value, $allowedScopes))) === count($allowedScopes), rule: 'F1');
        $this->requireRule(valid: ! $defaultScope->isWiderThan($maxScope), rule: 'F6');
        $this->requireRule(valid: in_array($defaultScope, $allowedScopes, true), rule: 'F2');
        $this->requireRule(valid: in_array($minorDefaultScope, $allowedScopes, true), rule: 'F3');
        foreach ($allowedScopes as $scope) {
            $this->requireRule(valid: ! $scope->isWiderThan($maxScope), rule: 'F4');
        }
        $this->requireRule(valid: ! $minorMaxScope->isWiderThan($maxScope), rule: 'F5');
        $this->requireRule(valid: ! $minorDefaultScope->isWiderThan($minorMaxScope), rule: 'F7');
        $this->requireRule(valid: ! in_array(Scope::Contour, $allowedScopes, true) || $contourLevels !== [], rule: 'F8');
        $this->requireRule(valid: array_is_list($contourLevels) && count(array_unique($contourLevels)) === count($contourLevels), rule: 'F9');
        foreach ($contourLevels as $level) {
            $this->requireRule(valid: preg_match('~^[a-z][a-z0-9_-]{0,31}$~D', $level) === 1, rule: 'F9');
        }
    }

    public function narrowedBy(self $surface): self
    {
        $levels = array_values(array_filter($this->contourLevels, fn (string $level): bool => in_array($level, $surface->contourLevels, true)));
        if (array_any($surface->allowedScopes, fn (Scope $scope): bool => ! in_array($scope, $this->allowedScopes, true))
            || $levels !== $surface->contourLevels
            || $surface->defaultScope->isWiderThan($this->defaultScope)
            || $surface->maxScope->isWiderThan($this->maxScope)
            || $surface->minorDefaultScope->isWiderThan($this->minorDefaultScope)
            || $surface->minorMaxScope->isWiderThan($this->minorMaxScope)) {
            throw new FloorWidensException(message: 'Surface floor widens platform floor', detail: ['rule' => 'SURFACE_FLOOR_WIDENS']);
        }

        return $surface;
    }

    public function maxFor(ActorContext $author): Scope
    {
        return $author->isMinorForPolicy() ? $this->minorMaxScope : $this->maxScope;
    }

    public function defaultFor(ActorContext $author): Scope
    {
        return $author->isMinorForPolicy() ? $this->minorDefaultScope : $this->defaultScope;
    }

    private function requireRule(bool $valid, string $rule): void
    {
        if (! $valid) {
            throw new InvalidPrivacyFloorException(message: 'Invalid privacy floor', detail: ['rule' => $rule]);
        }
    }
}
