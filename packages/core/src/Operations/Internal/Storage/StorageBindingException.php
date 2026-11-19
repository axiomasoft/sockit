<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use Packman\Attributes\Internal;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Internal]
final class StorageBindingException extends SocKitException
{
    public function problem(): ProblemCode
    {
        return ProblemCode::PanelBindingMismatch;
    }
}
