<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;

interface XHProfParserInterface
{
    public function parse(string $data): CallGraph;
}
