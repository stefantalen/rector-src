<?php

declare(strict_types=1);

namespace Rector\CodingStyle\ValueObject;

use PhpParser\Node\Expr;

final class ConcatStringAndPlaceholders
{
    /**
     * @param Expr[] $placeholderNodes
     */
    public function __construct(
        private readonly string $content,
        private readonly array $placeholderNodes
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return Expr[]
     */
    public function getPlaceholderNodes(): array
    {
        return $this->placeholderNodes;
    }
}
