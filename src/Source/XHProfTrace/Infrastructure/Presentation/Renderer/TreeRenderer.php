<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\Renderer;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;

interface TreeRenderer
{
    /**
     * Set filter for the renderer
     */
    public function setFilter(?XHProfTraceFilter $filter): void;

    /**
     * Render call graph as string
     */
    public function render(CallGraph $graph): string;
}
