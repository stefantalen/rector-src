<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocInfo;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use Rector\BetterPhpDocParser\Annotation\AnnotationNaming;
use Rector\BetterPhpDocParser\PhpDocNodeFinder\PhpDocNodeByTypeFinder;
use Rector\BetterPhpDocParser\PhpDocNodeMapper;
use Rector\BetterPhpDocParser\PhpDocParser\BetterPhpDocParser;
use Rector\BetterPhpDocParser\ValueObject\Parser\BetterTokenIterator;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\BetterPhpDocParser\ValueObject\StartAndEnd;
use Rector\ChangesReporting\Collector\RectorChangeCollector;
use Rector\Core\Configuration\CurrentNodeProvider;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\StaticTypeMapper\StaticTypeMapper;

final class PhpDocInfoFactory
{
    /**
     * @var array<string, PhpDocInfo>
     */
    private array $phpDocInfosByObjectHash = [];

    public function __construct(
        private readonly PhpDocNodeMapper $phpDocNodeMapper,
        private readonly CurrentNodeProvider $currentNodeProvider,
        private readonly Lexer $lexer,
        private readonly BetterPhpDocParser $betterPhpDocParser,
        private readonly StaticTypeMapper $staticTypeMapper,
        private readonly AnnotationNaming $annotationNaming,
        private readonly RectorChangeCollector $rectorChangeCollector,
        private readonly PhpDocNodeByTypeFinder $phpDocNodeByTypeFinder
    ) {
    }

    public function createFromNodeOrEmpty(Node $node): PhpDocInfo
    {
        // already added
        $phpDocInfo = $node->getAttribute(AttributeKey::PHP_DOC_INFO);
        if ($phpDocInfo instanceof PhpDocInfo) {
            return $phpDocInfo;
        }

        $phpDocInfo = $this->createFromNode($node);
        if ($phpDocInfo instanceof PhpDocInfo) {
            return $phpDocInfo;
        }

        return $this->createEmpty($node);
    }

    public function createFromNode(Node $node): ?PhpDocInfo
    {
        $objectHash = spl_object_hash($node);
        if (isset($this->phpDocInfosByObjectHash[$objectHash])) {
            return $this->phpDocInfosByObjectHash[$objectHash];
        }

        /** @see \Rector\BetterPhpDocParser\PhpDocParser\DoctrineAnnotationDecorator::decorate() */
        $this->currentNodeProvider->setNode($node);

        $docComment = $node->getDocComment();

        if (! $docComment instanceof Doc) {
            if ($node->getComments() === []) {
                return null;
            }

            // create empty node
            $tokenIterator = new BetterTokenIterator([]);
            $phpDocNode = new PhpDocNode([]);
        } else {
            $comments = $node->getComments();
            $docs = array_filter($comments, static fn (Comment $comment): bool => $comment instanceof Doc);

            if (count($docs) > 1) {
                $this->storePreviousDocs($node, $comments, $docComment);
            }

            $text = $docComment->getText();
            $tokens = $this->lexer->tokenize($text);
            $tokenIterator = new BetterTokenIterator($tokens);

            $phpDocNode = $this->betterPhpDocParser->parse($tokenIterator);
            $this->setPositionOfLastToken($phpDocNode);
        }

        $phpDocInfo = $this->createFromPhpDocNode($phpDocNode, $tokenIterator, $node);
        $this->phpDocInfosByObjectHash[$objectHash] = $phpDocInfo;

        return $phpDocInfo;
    }

    /**
     * @api
     */
    public function createEmpty(Node $node): PhpDocInfo
    {
        /** @see \Rector\BetterPhpDocParser\PhpDocParser\DoctrineAnnotationDecorator::decorate() */
        $this->currentNodeProvider->setNode($node);

        $phpDocNode = new PhpDocNode([]);
        $phpDocInfo = $this->createFromPhpDocNode($phpDocNode, new BetterTokenIterator([]), $node);

        // multiline by default
        $phpDocInfo->makeMultiLined();

        return $phpDocInfo;
    }

    /**
     * @param Comment[]|Doc[] $comments
     */
    private function storePreviousDocs(Node $node, array $comments, Doc $doc): void
    {
        $previousDocsAsComments = [];
        $newMainDoc = null;

        foreach ($comments as $comment) {
            // On last Doc, stop
            if ($comment === $doc) {
                break;
            }

            // pure comment
            if (! $comment instanceof Doc) {
                $previousDocsAsComments[] = $comment;
                continue;
            }

            // make Doc as comment Doc that not last
            $previousDocsAsComments[] = new Comment(
                $comment->getText(),
                $comment->getStartLine(),
                $comment->getStartFilePos(),
                $comment->getStartTokenPos(),
                $comment->getEndLine(),
                $comment->getEndFilePos(),
                $comment->getEndTokenPos()
            );

            /**
             * Make last Doc before main Doc to candidate main Doc
             * so it can immediatelly be used as replacement of Main doc when main doc removed
             */
            $newMainDoc = $comment;
        }

        $node->setAttribute(AttributeKey::PREVIOUS_DOCS_AS_COMMENTS, $previousDocsAsComments);
        $node->setAttribute(AttributeKey::NEW_MAIN_DOC, $newMainDoc);
    }

    /**
     * Needed for printing
     */
    private function setPositionOfLastToken(PhpDocNode $phpDocNode): void
    {
        if ($phpDocNode->children === []) {
            return;
        }

        $phpDocChildNodes = $phpDocNode->children;
        $phpDocChildNode = array_pop($phpDocChildNodes);
        $startAndEnd = $phpDocChildNode->getAttribute(PhpDocAttributeKey::START_AND_END);

        if ($startAndEnd instanceof StartAndEnd) {
            $phpDocNode->setAttribute(PhpDocAttributeKey::LAST_PHP_DOC_TOKEN_POSITION, $startAndEnd->getEnd());
        }
    }

    private function createFromPhpDocNode(
        PhpDocNode $phpDocNode,
        BetterTokenIterator $betterTokenIterator,
        Node $node
    ): PhpDocInfo {
        $this->phpDocNodeMapper->transform($phpDocNode, $betterTokenIterator);

        $phpDocInfo = new PhpDocInfo(
            $phpDocNode,
            $betterTokenIterator,
            $this->staticTypeMapper,
            $node,
            $this->annotationNaming,
            $this->currentNodeProvider,
            $this->rectorChangeCollector,
            $this->phpDocNodeByTypeFinder
        );

        $node->setAttribute(AttributeKey::PHP_DOC_INFO, $phpDocInfo);

        return $phpDocInfo;
    }
}
