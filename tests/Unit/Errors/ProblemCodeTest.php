<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

it('exposes immutable problem specifications and exception detail', function (): void {
    $previous = new RuntimeException('cause');
    $exception = new class('message', ['field' => 'panel'], $previous) extends SocKitException
    {
        public function problem(): ProblemCode
        {
            return ProblemCode::PanelNotFound;
        }
    };
    expect($exception->problem())->toBe(ProblemCode::PanelNotFound)
        ->and($exception->detail())->toBe(['field' => 'panel'])
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->problem()->spec()->httpStatus)->toBe(404);
});
