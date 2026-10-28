<?php

declare(strict_types=1);

namespace SocKit\Modules\Exceptions;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidModuleException extends SocKitException
{
    /** @param non-empty-list<array{module:string, key:string, rule:string, panel?:string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(message: 'Invalid SocKit module graph', detail: $errors[0]);
    }

    public function problem(): ProblemCode
    {
        return ProblemCode::ModuleInvalid;
    }
}
