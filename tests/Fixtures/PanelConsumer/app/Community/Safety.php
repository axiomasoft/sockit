<?php

declare(strict_types=1);

namespace Acme\Community;

use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\SafetyVerdict;
use SocKit\Posts\Enums\SafetyDecision;

final class Safety implements ContentSafety
{
    public SafetyDecision $decision = SafetyDecision::Allow;

    public function assess(OperationHandle $handle, PostCommand $post): SafetyVerdict
    {
        return new SafetyVerdict($this->decision, 'host.safety');
    }
}
