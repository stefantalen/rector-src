<?php

declare(strict_types=1);

namespace Rector\Php80\ValueObject;

use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;

final class DoctrineTagAndAnnotationToAttribute
{
    public function __construct(
        private readonly DoctrineAnnotationTagValueNode $doctrineAnnotationTagValueNode,
        private readonly AnnotationToAttribute $annotationToAttribute,
    ) {
    }

    public function getDoctrineAnnotationTagValueNode(): DoctrineAnnotationTagValueNode
    {
        return $this->doctrineAnnotationTagValueNode;
    }

    public function getAnnotationToAttribute(): AnnotationToAttribute
    {
        return $this->annotationToAttribute;
    }
}
