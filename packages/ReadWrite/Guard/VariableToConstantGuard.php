<?php

declare(strict_types=1);

namespace Rector\ReadWrite\Guard;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;

final class VariableToConstantGuard
{
    /**
     * @var array<string, array<int>>
     */
    private array $referencePositionsByFunctionName = [];

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function isReadArg(Arg $arg): bool
    {
        $node = $arg->getAttribute(AttributeKey::PARENT_NODE);
        if (! $node instanceof FuncCall) {
            return true;
        }

        $functionNameString = $this->nodeNameResolver->getName($node);
        if ($functionNameString === null) {
            return true;
        }

        $functionName = new Name($functionNameString);
        $argScope = $arg->getAttribute(AttributeKey::SCOPE);

        if (! $this->reflectionProvider->hasFunction($functionName, $argScope)) {
            // we don't know
            return true;
        }

        $functionReflection = $this->reflectionProvider->getFunction($functionName, $argScope);

        if (! $argScope instanceof Scope) {
            return true;
        }

        $referenceParametersPositions = $this->resolveFunctionReferencePositions(
            $functionReflection,
            $node,
            $argScope
        );
        if ($referenceParametersPositions === []) {
            // no reference always only write
            return true;
        }

        $argumentPosition = $this->getArgumentPosition($node, $arg);
        return ! in_array($argumentPosition, $referenceParametersPositions, true);
    }

    /**
     * @return int[]
     */
    private function resolveFunctionReferencePositions(
        FunctionReflection $functionReflection,
        CallLike $callLike,
        Scope $scope
    ): array {
        if (isset($this->referencePositionsByFunctionName[$functionReflection->getName()])) {
            return $this->referencePositionsByFunctionName[$functionReflection->getName()];
        }

        $referencePositions = [];

        $parametersAcceptor = ParametersAcceptorSelectorVariantsWrapper::select(
            $functionReflection,
            $callLike,
            $scope
        );
        foreach ($parametersAcceptor->getParameters() as $position => $parameterReflection) {
            /** @var ParameterReflection $parameterReflection */
            if (! $parameterReflection->passedByReference()->yes()) {
                continue;
            }

            $referencePositions[] = $position;
        }

        $this->referencePositionsByFunctionName[$functionReflection->getName()] = $referencePositions;

        return $referencePositions;
    }

    private function getArgumentPosition(FuncCall $funcCall, Arg $desiredArg): int
    {
        foreach ($funcCall->args as $position => $arg) {
            if ($arg !== $desiredArg) {
                continue;
            }

            return $position;
        }

        throw new ShouldNotHappenException();
    }
}
