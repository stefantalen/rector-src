<?php declare(strict_types=1);

namespace Rector\DeprecationExtractor\Transformer;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\MagicConst\Class_;
use PhpParser\Node\Scalar\MagicConst\Method;
use PhpParser\Node\Scalar\MagicConst\Namespace_;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use Rector\DeprecationExtractor\Contract\Deprecation\DeprecationInterface;
use Rector\DeprecationExtractor\Deprecation\ClassMethodDeprecation;
use Rector\DeprecationExtractor\Deprecation\RemovedFunctionalityDeprecation;
use Rector\DeprecationExtractor\RegExp\ClassAndMethodMatcher;
use Rector\Exception\NotImplementedException;
use Rector\Node\Attribute;
use Rector\NodeValueResolver\NodeValueResolver;

final class ArgumentToDeprecationTransformer
{
    /**
     * @var ClassAndMethodMatcher
     */
    private $classAndMethodMatcher;

    /**
     * @var Standard
     */
    private $standardPrinter;

    /**
     * @var NodeValueResolver
     */
    private $nodeValueResolver;

    public function __construct(
        ClassAndMethodMatcher $classAndMethodMatcher,
        Standard $standardPrinter,
        NodeValueResolver $nodeValueResolver
    ) {
        $this->classAndMethodMatcher = $classAndMethodMatcher;
        $this->standardPrinter = $standardPrinter;
        $this->nodeValueResolver = $nodeValueResolver;
    }

    /**
     * Probably resolve by recursion, similar too
     * @see \Rector\NodeTypeResolver\NodeVisitor\TypeResolver::__construct()
     */
    public function transform(Arg $argNode): ?DeprecationInterface
    {
        $message = '';
        if ($argNode->value instanceof Concat) {
            $message .= $this->processConcatNode($argNode->value->left);
            $message .= $this->processConcatNode($argNode->value->right);

            $value = $this->nodeValueResolver->resolve($argNode->value);
            $value = $this->completeClassToLocalMethods($value, (string) $argNode->getAttribute(Attribute::CLASS_NAME));

        } elseif ($argNode->value instanceof FuncCall) {
            if ((string) $argNode->value->name === 'sprintf') {
                $message = $this->processSprintfNode($argNode->value);
                $message = $this->completeClassToLocalMethods($message, (string) $argNode->getAttribute(Attribute::CLASS_NAME));
            }

            if ($message === '') {
                return null;
            }
        } elseif ($argNode->value instanceof String_) {
            $message = $argNode->value->value;
        } elseif ($argNode->value instanceof Variable) {
            // @todo: get value?
            $message = '$' . $argNode->value->name;
        } elseif ($argNode->value instanceof MethodCall) {
            $message = $this->standardPrinter->prettyPrint([$argNode->value]);
        }

        if ($message === '') {
            throw new NotImplementedException(sprintf(
                'Not implemented yet. Go to "%s()" and add check for "%s" node.',
                __METHOD__,
                get_class($argNode->value)
            ));
        }

        return $this->createFromMesssage($message);
    }

    public function tryToCreateClassMethodDeprecation(string $oldMessage, string $newMessage): ?DeprecationInterface
    {
        $oldMethod = $this->classAndMethodMatcher->matchClassWithMethod($oldMessage);
        $newMethod = $this->classAndMethodMatcher->matchClassWithMethod($newMessage);

        return new ClassMethodDeprecation($oldMethod, $newMethod);
    }

    private function processConcatNode(Node $node): string
    {
        if ($node instanceof Method) {
            $classMethodNode = $node->getAttribute(Attribute::SCOPE_NODE);

            return $node->getAttribute(Attribute::CLASS_NAME) . '::' . $classMethodNode->name->name;
        }

        if ($node instanceof String_) {
            $message = $node->value; // complet class to local methods
            return $this->completeClassToLocalMethods($message, (string) $node->getAttribute(Attribute::CLASS_NAME));
        }

        if ($node instanceof Concat) {
            $message = $this->processConcatNode($node->left);
            $message .= $this->processConcatNode($node->right);

            return $message;
        }

        if ($node instanceof Namespace_) {
            return (string) $node->getAttribute(Attribute::NAMESPACE);
        }

        if ($node instanceof Class_) {
            return (string) $node->getAttribute(Attribute::CLASS_NAME);
        }

        throw new NotImplementedException(sprintf(
            'Not implemented yet. Go to "%s()" and add check for "%s" node.',
            __METHOD__,
            get_class($node)
        ));
    }

    private function completeClassToLocalMethods(string $message, string $class): string
    {
        $completeMessage = '';
        $words = explode(' ', $message);

        foreach ($words as $word) {
            $completeMessage .= ' ' . $this->prependClassToMethodCallIfNeeded($word, $class);
        }

        return trim($completeMessage);
    }

    private function prependClassToMethodCallIfNeeded(string $word, string $class): string
    {
        // is method()
        if (Strings::endsWith($word, '()') && strlen($word) > 2) {
            // doesn't include class in the beggning
            if (! Strings::startsWith($word, $class)) {
                return $class . '::' . $word;
            }
        }

        // is method('...')
        if (Strings::endsWith($word, '\')')) {
            // doesn't include class in the beggning
            if (! Strings::startsWith($word, $class)) {
                return $class . '::' . $word;
            }
        }

        return $word;
    }

    private function createFromMesssage(string $message): DeprecationInterface
    {
        $result = Strings::split($message, '#^use |Use#');
        if (count($result) === 2) {
            [$oldMessage, $newMessage] = $result;
            $deprecation = $this->tryToCreateClassMethodDeprecation($oldMessage, $newMessage);
            if ($deprecation) {
                return $deprecation;
            }
        }

        return new RemovedFunctionalityDeprecation($message);

        throw new NotImplementedException(sprintf(
            '%s() did not resolve "%s" messsage, so %s was not created. Implement it.',
            __METHOD__,
            $message,
            DeprecationInterface::class
        ));
    }

    private function processSprintfNode(FuncCall $funcCallNode): string
    {
        if ((string) $funcCallNode->name !== 'sprintf') {
            // or Exception?
            return '';
        }

        if ($this->isDynamicSprintf($funcCallNode)) {
            return '';
        }

        $sprintfMessage = '';

        $arguments = $funcCallNode->args;
        $argumentCount = count($arguments);

        $firstArgument = $arguments[0]->value;
        if ($firstArgument instanceof String_) {
            $sprintfMessage = $firstArgument->value;
        } else {
            // todo
            dump($firstArgument);
            die;
        }

        $sprintfArguments = [];
        for ($i = 1; $i < $argumentCount; ++$i) {
            $argument = $arguments[$i];
            if ($argument->value instanceof Method) {
                /** @var Node\Stmt\ClassMethod $methodNode */
                $methodNode = $funcCallNode->getAttribute(Attribute::SCOPE_NODE);
                $sprintfArguments[] = (string) $methodNode->name;
            } elseif ($argument->value instanceof ClassConstFetch) {
                $value = $this->standardPrinter->prettyPrint([$argument->value]);
                if ($value === 'static::class') {
                    $sprintfArguments[] = $argument->value->getAttribute(Attribute::CLASS_NAME);
                }
            } else {
                dump($this->standardPrinter->prettyPrint([$argument]));
                die;

                throw new NotImplementedException(sprintf(
                    'Not implemented yet. Go to "%s()" and add check for "%s" argument node.',
                    __METHOD__,
                    get_class($argument->value)
                ));
            }
        }

        return sprintf($sprintfMessage, ...$sprintfArguments);
    }

    private function isDynamicSprintf(FuncCall $funcCallNode): bool
    {
        foreach ($funcCallNode->args as $argument) {
            if ($this->isDynamicArgument($argument)) {
                return true;
            }
        }

        return false;
    }

    private function isDynamicArgument(Arg $argument): bool
    {
        $valueNodeClass = get_class($argument->value);

        return in_array($valueNodeClass, [PropertyFetch::class, MethodCall::class, Variable::class], true);
    }
}
