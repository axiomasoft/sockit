<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Receipts;

use Packman\Attributes\Internal;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Internal]
final class ReceiptException extends SocKitException
{
    public function __construct(private readonly ProblemCode $codeValue)
    {
        parent::__construct('Receipt request rejected');
    }

    public function problem(): ProblemCode
    {
        return $this->codeValue;
    }
}
