<?php

declare(strict_types=1);

namespace Rector\Arguments\ValueObject;

final class SwapFuncCallArguments
{
    /**
     * @param array<int, int> $order
     */
    public function __construct(
        private readonly string $function,
        private readonly array $order
    ) {
    }

    public function getFunction(): string
    {
        return $this->function;
    }

    /**
     * @return array<int, int>
     */
    public function getOrder(): array
    {
        return $this->order;
    }
}
