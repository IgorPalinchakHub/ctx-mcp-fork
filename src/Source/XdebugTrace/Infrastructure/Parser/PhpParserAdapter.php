<?php
// Path: /src/Source/XdebugTrace/Infrastructure/Parser/PhpParserAdapter.php

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\TypeInferenceService;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Psr\Log\LoggerInterface;

/**
 * Adapter for PHP Parser library to work with PHP AST
 */
class PhpParserAdapter
{
    private Parser $parser;
    private NodeFinder $nodeFinder;
    private PrettyPrinter $prettyPrinter;
    private array $parsedFiles = [];


    public function __construct(
        private TypeRepository $typeRepository,
        private TypeInferenceService $typeInferenceService,
        private ?LoggerInterface $logger = null,
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
        $this->prettyPrinter = new PrettyPrinter();
    }


    public function parseFile(string $filePath): ?array
    {
        // Return cached parse result if already parsed
        if (isset($this->parsedFiles[$filePath])) {
            return $this->parsedFiles[$filePath];
        }

        if (!file_exists($filePath)) {
            $this->logger?->warning("File does not exist: {$filePath}");
            return null;
        }

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                $this->logger?->error("Failed to read file: {$filePath}");
                return null;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                $this->logger?->error("Failed to parse file: {$filePath}");
                return null;
            }

            // Apply name resolution to the AST
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            // Cache the parsed result
            $this->parsedFiles[$filePath] = $ast;

            // Extract and store type information from the file
            $this->extractTypeInformation($ast, $filePath);

            return $ast;
        } catch (\Throwable $e) {
            $this->logger?->error("Error parsing file {$filePath}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }


    private function extractTypeInformation(array $ast, string $filePath): void
    {
        // Find class definitions
        $classes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if (!$class->namespacedName) {
                continue;
            }

            $className = $class->namespacedName->toString();

            // Process methods
            foreach ($class->getMethods() as $method) {
                $methodName = $method->name->toString();

                // Extract method return type
                $returnType = $this->extractMethodReturnType($method);
                if ($returnType) {
                    $this->typeRepository->setMethodReturnType($className, $methodName, $returnType);
                }

                // Extract method parameters
                $params = $this->extractMethodParameters($method);
                if (!empty($params)) {
                    $this->typeRepository->storeMethodParams($className, $methodName, $params);
                }
            }

            // Process properties
            foreach ($class->getProperties() as $property) {
                if ($property->type) {
                    $propertyName = $property->props[0]->name->toString();
                    $propertyType = $this->resolveTypeNodeToClassName($property->type);

                    if ($propertyType) {
                        $this->typeRepository->setPropertyType($className, $propertyName, $propertyType);
                    }
                } elseif ($property->getDocComment()) {
                    // Try to extract type from PHPDoc
                    $propertyName = $property->props[0]->name->toString();
                    $docComment = $property->getDocComment()->getText();
                    $propertyType = $this->typeInferenceService->extractPropertyTypeFromDocComment(
                        $docComment,
                        $propertyName
                    );

                    if ($propertyType) {
                        $this->typeRepository->setPropertyType($className, $propertyName, $propertyType);
                    }
                }
            }

            // Mark this class as having complete type info
            $this->typeRepository->markTypeInfoComplete($className);
        }
    }


    public function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return $node->name->toString();
            }
        }

        return null;
    }


    public function extractClassName(array $ast): ?string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $subStmt) {
                    if ($subStmt instanceof Node\Stmt\Class_ ||
                        $subStmt instanceof Node\Stmt\Interface_ ||
                        $subStmt instanceof Node\Stmt\Trait_) {
                        return $subStmt->name->toString();
                    }
                }
            } elseif ($stmt instanceof Node\Stmt\Class_ ||
                $stmt instanceof Node\Stmt\Interface_ ||
                $stmt instanceof Node\Stmt\Trait_) {
                return $stmt->name->toString();
            }
        }

        return null;
    }


    public function findClassMethods(array $ast): array
    {
        return $this->nodeFinder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
    }


    public function findClassMethod(array $ast, string $methodName): ?Node\Stmt\ClassMethod
    {
        $methods = $this->findClassMethods($ast);

        foreach ($methods as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Find instances of a specific node type in the AST
     */
    public function findInstanceOf(array $ast, string $nodeType): array
    {
        return $this->nodeFinder->findInstanceOf($ast, $nodeType);
    }


    public function findClass(array $ast, string $className): ?Node\Stmt\Class_
    {
        $classes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);
        $shortClassName = $this->getShortClassName($className);

        // First try exact match
        foreach ($classes as $class) {
            if ($class->namespacedName && $class->namespacedName->toString() === $className) {
                return $class;
            }
        }

        // Try short name match
        $matchedByShortName = null;
        foreach ($classes as $class) {
            if ($class->name->toString() === $shortClassName) {
                $matchedByShortName = $class;
                break;
            }
        }

        // If found by short name, use it
        if ($matchedByShortName) {
            return $matchedByShortName;
        }

        // Try with namespace if available
        $namespace = $this->extractNamespace($ast);
        if ($namespace) {
            $possibleClassName = $namespace . '\\' . $shortClassName;
            foreach ($classes as $class) {
                if ($class->namespacedName && $class->namespacedName->toString() === $possibleClassName) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Get the short class name from a fully qualified name
     */
    private function getShortClassName(string $fullyQualifiedName): string
    {
        $parts = explode('\\', $fullyQualifiedName);
        return end($parts);
    }


    public function createNodeTraverser(): NodeTraverser
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        return $traverser;
    }


    public function extractMethodParameters(Node\Stmt\ClassMethod $methodNode): array
    {
        $params = [];

        foreach ($methodNode->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $paramName = $param->var->name;
                $paramType = null;

                if ($param->type) {
                    $paramType = $this->resolveTypeNodeToClassName($param->type);
                } else {
                    // Try to extract parameter type from docblock
                    $docComment = $methodNode->getDocComment();
                    if ($docComment) {
                        $paramTypes = $this->typeInferenceService->extractParamTypesFromDocComment($docComment->getText());
                        if (isset($paramTypes[$paramName])) {
                            $paramType = $paramTypes[$paramName];
                        }
                    }
                }

                $params[$paramName] = [
                    'name' => $paramName,
                    'type' => $paramType,
                    'hasDefault' => $param->default !== null,
                    'defaultValue' => $param->default !== null ? $this->formatDefaultValue($param->default) : null,
                ];
            }
        }

        return $params;
    }


    private function formatDefaultValue(Node\Expr $default): string
    {
        try {
            return $this->prettyPrinter->prettyPrintExpr($default);
        } catch (\Throwable $e) {
            if ($default instanceof Node\Scalar\String_) {
                return "'" . $default->value . "'";
            }

            if ($default instanceof Node\Scalar\LNumber) {
                return (string)$default->value;
            }

            if ($default instanceof Node\Scalar\DNumber) {
                return (string)$default->value;
            }

            if ($default instanceof Node\Expr\ConstFetch) {
                return $default->name->toString();
            }

            if ($default instanceof Node\Expr\Array_) {
                return '[]';
            }

            return '[default value]';
        }
    }


    public function extractMethodReturnType(Node\Stmt\ClassMethod $methodNode): ?string
    {
        if ($methodNode->returnType) {
            return $this->resolveTypeNodeToClassName($methodNode->returnType);
        }

        // Check PHPDoc for return type
        $docComment = $methodNode->getDocComment();
        if ($docComment) {
            return $this->typeInferenceService->extractReturnTypeFromDocComment($docComment->getText());
        }

        return null;
    }


    public function resolveTypeNodeToClassName($typeNode): ?string
    {
        if ($typeNode instanceof Node\Name) {
            $typeName = $typeNode->toString();

            // Handle basic types vs class names
            if (in_array(strtolower($typeName), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed', 'void', 'null', 'resource', 'callable', 'iterable'])) {
                return strtolower($typeName);
            }

            return $typeName;
        }

        if ($typeNode instanceof Node\NullableType) {
            $type = $this->resolveTypeNodeToClassName($typeNode->type);
            return $type ? $type : null;
        }

        if ($typeNode instanceof Node\UnionType && isset($typeNode->types)) {
            // Attempt to find the first valid class in a union
            foreach ($typeNode->types as $subType) {
                $result = $this->resolveTypeNodeToClassName($subType);
                if ($result && $result !== 'null') {
                    return $result;
                }
            }
        }

        if ($typeNode instanceof Node\IntersectionType && isset($typeNode->types)) {
            // For intersection types, return the first type as primary
            if (!empty($typeNode->types)) {
                return $this->resolveTypeNodeToClassName($typeNode->types[0]);
            }
        }

        return null;
    }


    public function formatExpr(Node\Expr $expr): string
    {
        try {
            return $this->prettyPrinter->prettyPrintExpr($expr);
        } catch (\Throwable $e) {
            // Fallback for various node types
            if ($expr instanceof Node\Scalar\String_) {
                return "'" . $expr->value . "'";
            }

            if ($expr instanceof Node\Scalar\LNumber) {
                return (string)$expr->value;
            }

            if ($expr instanceof Node\Scalar\DNumber) {
                return (string)$expr->value;
            }

            if ($expr instanceof Node\Expr\ConstFetch) {
                return $expr->name->toString();
            }

            if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                return '$' . $expr->name;
            }

            if ($expr instanceof Node\Expr\Array_) {
                return '[array]';
            }

            return '[expression]';
        }
    }


    public function clearCache(): void
    {
        $this->parsedFiles = [];
    }
}
