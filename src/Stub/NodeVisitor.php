<?php

namespace Castor\Stub;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/** @internal */
class NodeVisitor extends NodeVisitorAbstract
{
    /** @var array<string, Node\Name> */
    private array $currentUseStatements = [];

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Function_) {
            $node->stmts = [];
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $node->stmts = [];
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentUseStatements = [];
            $node->setAttribute('comments', null);
        }

        if ($node instanceof Node\Stmt\UseUse) {
            $this->currentUseStatements[$node->getAlias()->name] = $node->name;
        }

        // replace relative by fqdn
        if ($node instanceof Node\Name && !$node->isFullyQualified()) {
            $name = $node->toString();

            if (isset($this->currentUseStatements[$name])) {
                return new Node\Name\FullyQualified($this->currentUseStatements[$name]->parts);
            }
        }

        return null;
    }

    public function leaveNode(Node $node, bool $preserveStack = false): ?int
    {
        $docComment = $node->getDocComment();

        if (null !== $docComment && str_contains($docComment->getText(), '@internal')) {
            return NodeTraverser::REMOVE_NODE;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            if (empty($node->stmts)) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Node\Stmt\Use_) {
            return NodeTraverser::REMOVE_NODE;
        }

        return null;
    }
}
