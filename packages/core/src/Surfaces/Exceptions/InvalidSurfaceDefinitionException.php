<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Exceptions;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidSurfaceDefinitionException extends SocKitException
{
    /** @param non-empty-list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(message: "Invalid SocKit surface graph\n".implode("\n", $errors), detail: ['rule' => explode(' ', $errors[0])[0]]);
    }

    public static function at(string $code, string $provider = '', string $panel = '', string $key = 'surface'): self
    {
        return new self([$code.' ['.$provider.'] panel='.$panel.' key='.$key]);
    }

    public function problem(): ProblemCode
    {
        return ProblemCode::ConfigurationInvalid;
    }
}
