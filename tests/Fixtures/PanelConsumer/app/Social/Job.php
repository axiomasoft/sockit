<?php

declare(strict_types=1);

namespace Acme\Social;

use Illuminate\Queue\SerializesModels;
use SocKit\Schema\PanelModel;

final class Job
{
    use SerializesModels;

    public function __construct(public PanelModel $model) {}
}
