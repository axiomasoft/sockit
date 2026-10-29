<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class ArticlePanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('host.articles')->hostTypes('article')->connection('social')->prefix('articles_')
            ->models(['receipts' => ReceiptModel::class])->modules('demo.module')->moduleSettings('demo.module', ['limit' => 30]);
    }
}
