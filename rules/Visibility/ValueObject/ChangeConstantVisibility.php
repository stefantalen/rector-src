<?php

declare(strict_types=1);

namespace Rector\Visibility\ValueObject;

use PHPStan\Type\ObjectType;

final class ChangeConstantVisibility
{
    public function __construct(
        private readonly string $class,
        private readonly string $constant,
        private readonly int $visibility
    ) {
    }

    public function getObjectType(): ObjectType
    {
        return new ObjectType($this->class);
    }

    public function getConstant(): string
    {
        return $this->constant;
    }

    public function getVisibility(): int
    {
        return $this->visibility;
    }
}
