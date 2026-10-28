<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

final class ModelQueueJob
{
    use SerializesModels;

    public function __construct(public Model $model) {}
}
