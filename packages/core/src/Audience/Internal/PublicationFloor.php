<?php

declare(strict_types=1);

namespace SocKit\Audience\Internal;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;

final class PublicationFloor
{
    public static function denial(ActorContext $author, AudienceSpec $spec, PrivacyFloor $floor): ?AudienceVerdict
    {
        if ($spec->operator !== null) {
            foreach ($spec->operator === 'exclude' ? [$spec->children[0]] : $spec->children as $child) {
                if (($denied = self::denial($author, $child, $floor)) !== null) {
                    return $denied;
                }
            }

            return null;
        }
        if ($spec->scope === null || ! in_array($spec->scope, $floor->allowedScopes, true)) {
            return AudienceVerdict::deny('audience.denied.scope_not_allowed');
        }
        if ($spec->scope->isWiderThan($floor->maxFor($author))) {
            return AudienceVerdict::deny('audience.denied.scope_too_wide');
        }
        if ($spec->scope === Scope::Contour && ! in_array($spec->contourLevel, $floor->contourLevels, true)) {
            return AudienceVerdict::deny('audience.denied.contour_level_unknown');
        }

        return null;
    }
}
