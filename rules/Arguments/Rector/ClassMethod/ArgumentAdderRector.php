<?php

declare(strict_types=1);

namespace Rector\Arguments\Rector\ClassMethod;

use PhpParser\BuilderHelpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Rector\Arguments\NodeAnalyzer\ArgumentAddingScope;
use Rector\Arguments\NodeAnalyzer\ChangedArgumentsDetector;
use Rector\Arguments\ValueObject\ArgumentAdder;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Enum\ObjectReference;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\AstResolver;
use Rector\Core\PhpParser\Printer\BetterStandardPrinter;
use Rector\Core\Rector\AbstractRector;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Webmozart\Assert\Assert;

/**
 * @see \Rector\Tests\Arguments\Rector\ClassMethod\ArgumentAdderRector\ArgumentAdderRectorTest
 */
final class ArgumentAdderRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var ArgumentAdder[]
     */
    private array $addedArguments = [];

    private bool $hasChanged = false;

    public function __construct(
        private readonly ArgumentAddingScope $argumentAddingScope,
        private readonly ChangedArgumentsDetector $changedArgumentsDetector,
        private readonly AstResolver $astResolver,
        private readonly BetterStandardPrinter $betterStandardPrinter
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'This Rector adds new default arguments in calls of defined methods and class types.',
            [
                new ConfiguredCodeSample(
                    <<<'CODE_SAMPLE'
$someObject = new SomeExampleClass;
$someObject->someMethod();

class MyCustomClass extends SomeExampleClass
{
    public function someMethod()
    {
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$someObject = new SomeExampleClass;
$someObject->someMethod(true);

class MyCustomClass extends SomeExampleClass
{
    public function someMethod($value = true)
    {
    }
}
CODE_SAMPLE
                    ,
                    [
                        new ArgumentAdder(
                            'SomeExampleClass',
                            'someMethod',
                            0,
                            'someArgument',
                            true,
                            new ObjectType('SomeType')
                        ),
                    ]
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class, Class_::class];
    }

    /**
     * @param MethodCall|StaticCall|Class_ $node
     */
    public function refactor(Node $node): MethodCall | StaticCall | Class_ | null
    {
        $this->hasChanged = false;

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            $this->refactorCall($node);
        } else {
            foreach ($node->getMethods() as $classMethod) {
                $this->refactorClassMethod($node, $classMethod);
            }
        }

        if ($this->hasChanged) {
            return $node;
        }

        return null;
    }

    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration): void
    {
        Assert::allIsAOf($configuration, ArgumentAdder::class);
        $this->addedArguments = $configuration;
    }

    private function isObjectTypeMatch(MethodCall | StaticCall $call, ObjectType $objectType): bool
    {
        if ($call instanceof MethodCall) {
            return $this->isObjectType($call->var, $objectType);
        }

        return $this->isObjectType($call->class, $objectType);
    }

    private function processPositionWithDefaultValues(
        ClassMethod | MethodCall | StaticCall $node,
        ArgumentAdder $argumentAdder
    ): void {
        if ($this->shouldSkipParameter($node, $argumentAdder)) {
            return;
        }

        $defaultValue = $argumentAdder->getArgumentDefaultValue();
        $argumentType = $argumentAdder->getArgumentType();

        $position = $argumentAdder->getPosition();

        if ($node instanceof ClassMethod) {
            $this->addClassMethodParam($node, $argumentAdder, $defaultValue, $argumentType, $position);
            return;
        }

        if ($node instanceof StaticCall) {
            $this->processStaticCall($node, $position, $argumentAdder);
            return;
        }

        $this->processMethodCall($node, $defaultValue, $position);
    }

    private function processMethodCall(MethodCall $methodCall, mixed $defaultValue, int $position): void
    {
        $arg = new Arg(BuilderHelpers::normalizeValue($defaultValue));
        if (isset($methodCall->args[$position])) {
            return;
        }

        $this->fillGapBetweenWithDefaultValue($methodCall, $position);

        $methodCall->args[$position] = $arg;
        $this->hasChanged = true;
    }

    private function fillGapBetweenWithDefaultValue(MethodCall | StaticCall $node, int $position): void
    {
        $lastPosition = count($node->getArgs()) - 1;

        if ($position <= $lastPosition) {
            return;
        }

        if ($position - $lastPosition === 1) {
            return;
        }

        $classMethod = $this->astResolver->resolveClassMethodFromCall($node);
        if (! $classMethod instanceof ClassMethod) {
            return;
        }

        for ($index = $lastPosition + 1; $index < $position; ++$index) {
            $param = $classMethod->params[$index];
            if (! $param->default instanceof Expr) {
                throw new ShouldNotHappenException('Previous position does not have default value');
            }

            $default = $this->betterStandardPrinter->print($param->default);
            $node->args[$index] = new Arg(new ConstFetch(new Name($default)));
        }
    }

    private function shouldSkipParameter(
        ClassMethod | MethodCall | StaticCall $node,
        ArgumentAdder $argumentAdder
    ): bool {
        $position = $argumentAdder->getPosition();
        $argumentName = $argumentAdder->getArgumentName();

        if ($argumentName === null) {
            return true;
        }

        if ($node instanceof ClassMethod) {
            // already added?
            if (! isset($node->params[$position])) {
                return false;
            }

            $param = $node->params[$position];
            // argument added and name has been changed
            if (! $this->isName($param, $argumentName)) {
                return true;
            }

            // argument added and default has been changed
            if ($this->changedArgumentsDetector->isDefaultValueChanged(
                $param,
                $argumentAdder->getArgumentDefaultValue()
            )) {
                return true;
            }

            // argument added and type has been changed
            return $this->changedArgumentsDetector->isTypeChanged($param, $argumentAdder->getArgumentType());
        }

        if (isset($node->args[$position])) {
            return true;
        }

        // is correct scope?
        return ! $this->argumentAddingScope->isInCorrectScope($node, $argumentAdder);
    }

    private function addClassMethodParam(
        ClassMethod $classMethod,
        ArgumentAdder $argumentAdder,
        mixed $defaultValue,
        ?Type $type,
        int $position
    ): void {
        $argumentName = $argumentAdder->getArgumentName();
        if ($argumentName === null) {
            throw new ShouldNotHappenException();
        }

        $param = new Param(new Variable($argumentName), BuilderHelpers::normalizeValue($defaultValue));
        if ($type instanceof Type) {
            $param->type = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($type, TypeKind::PARAM);
        }

        $classMethod->params[$position] = $param;
        $this->hasChanged = true;
    }

    private function processStaticCall(StaticCall $staticCall, int $position, ArgumentAdder $argumentAdder): void
    {
        $argumentName = $argumentAdder->getArgumentName();
        if ($argumentName === null) {
            throw new ShouldNotHappenException();
        }

        if (! $staticCall->class instanceof Name) {
            return;
        }

        if (! $this->isName($staticCall->class, ObjectReference::PARENT)) {
            return;
        }

        $this->fillGapBetweenWithDefaultValue($staticCall, $position);

        $staticCall->args[$position] = new Arg(new Variable($argumentName));
        $this->hasChanged = true;
    }

    private function refactorCall(StaticCall|MethodCall $call): void
    {
        foreach ($this->addedArguments as $addedArgument) {
            if (! $this->isName($call->name, $addedArgument->getMethod())) {
                continue;
            }

            if (! $this->isObjectTypeMatch($call, $addedArgument->getObjectType())) {
                continue;
            }

            $this->processPositionWithDefaultValues($call, $addedArgument);
        }
    }

    private function refactorClassMethod(Class_ $class, ClassMethod $classMethod): void
    {
        foreach ($this->addedArguments as $addedArgument) {
            if (! $this->isName($classMethod, $addedArgument->getMethod())) {
                continue;
            }

            if (! $this->isObjectType($class, $addedArgument->getObjectType())) {
                continue;
            }

            $this->processPositionWithDefaultValues($classMethod, $addedArgument);
        }
    }
}
