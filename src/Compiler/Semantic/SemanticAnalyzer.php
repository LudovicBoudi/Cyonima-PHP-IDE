<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

use Cyonima\Compiler\Ast\AppendExpr;
use Cyonima\Compiler\Ast\ArrayExpr;
use Cyonima\Compiler\Ast\AssignExpr;
use Cyonima\Compiler\Ast\BinaryExpr;
use Cyonima\Compiler\Ast\Block;
use Cyonima\Compiler\Ast\BreakStmt;
use Cyonima\Compiler\Ast\CallableCallExpr;
use Cyonima\Compiler\Ast\CallExpr;
use Cyonima\Compiler\Ast\ClassDecl;
use Cyonima\Compiler\Ast\ClosureExpr;
use Cyonima\Compiler\Ast\EchoStmt;
use Cyonima\Compiler\Ast\Expr;
use Cyonima\Compiler\Ast\ExpressionStmt;
use Cyonima\Compiler\Ast\ForStmt;
use Cyonima\Compiler\Ast\ForeachStmt;
use Cyonima\Compiler\Ast\FunctionDecl;
use Cyonima\Compiler\Ast\IfStmt;
use Cyonima\Compiler\Ast\IndexAssignExpr;
use Cyonima\Compiler\Ast\IndexExpr;
use Cyonima\Compiler\Ast\Literal;
use Cyonima\Compiler\Ast\MatchExpr;
use Cyonima\Compiler\Ast\MethodCallExpr;
use Cyonima\Compiler\Ast\NewExpr;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ast\PropertyAccessExpr;
use Cyonima\Compiler\Ast\PropertyAssignExpr;
use Cyonima\Compiler\Ast\ReturnStmt;
use Cyonima\Compiler\Ast\Stmt;
use Cyonima\Compiler\Ast\SwitchStmt;
use Cyonima\Compiler\Ast\ThrowStmt;
use Cyonima\Compiler\Ast\TryStmt;
use Cyonima\Compiler\Ast\UnaryExpr;
use Cyonima\Compiler\Ast\VariableExpr;
use Cyonima\Compiler\Ast\WhileStmt;
use Cyonima\Compiler\CompilerException;

/**
 * Vérification de types et de portée du sous-ensemble PHP.
 *
 * Les variables sont à portée de fonction, comme en PHP. Les types doivent
 * rester cohérents : une variable `float` accepte un entier, l’inverse est
 * refusé. Toute expression non typable échoue à la compilation.
 */
final class SemanticAnalyzer
{
    private int $breakDepth = 0;

    /** @var array<string, ClassInfo> */
    private array $classes = [];

    /**
     * @param array<string, FunctionInfo> $functions
     */
    public function analyze(Program $program): Analysis
    {
        $functions = $this->collectFunctions($program);
        $classes = $this->collectClasses($program);
        $this->classes = $classes;

        foreach ($program->functions as $function) {
            $info = $functions[$function->name];
            $scope = new Scope($info->params);
            $this->analyzeStatements($function->body->statements, $scope, $info->returnType, $functions);
            $info->variables = $scope->variables;

            $assigned = array_fill_keys(array_keys($info->params), true);
            [, $returns] = $this->flowStatements($function->body->statements, $assigned);

            if ($info->returnType !== 'void' && !$returns) {
                throw new CompilerException(
                    "ligne {$function->line} : la fonction {$function->name} ne retourne pas sur tous les chemins",
                );
            }
        }

        foreach ($classes as $class) {
            foreach ($class->methods as $methodName => $method) {
                $declaration = $class->methodDeclarations[$methodName]
                    ?? throw new CompilerException(
                        "méthode absente de l’AST : {$class->name}::{$methodName}",
                    );
                $scope = new Scope(['this' => $class->name] + $method->params);
                $this->analyzeStatements(
                    $declaration->body->statements,
                    $scope,
                    $method->returnType,
                    $functions,
                );
                $method->variables = $scope->variables;

                $assigned = ['this' => true];

                foreach ($method->params as $name => $_type) {
                    $assigned[$name] = true;
                }

                [, $returns] = $this->flowStatements($declaration->body->statements, $assigned);

                if ($method->returnType !== 'void' && !$returns) {
                    throw new CompilerException(
                        "ligne {$declaration->line} : la méthode {$class->name}::{$method->name} ne retourne pas sur tous les chemins",
                    );
                }
            }
        }

        $mainScope = new Scope();
        $this->analyzeStatements($program->statements, $mainScope, 'int', $functions);
        $this->flowStatements($program->statements, []);

        return new Analysis($functions, $classes, $mainScope->variables);
    }

    /**
     * @return array<string, FunctionInfo>
     */
    private function collectFunctions(Program $program): array
    {
        $functions = [];

        foreach ($program->functions as $function) {
            if (isset($functions[$function->name]) || $this->builtin($function->name) !== null) {
                throw new CompilerException(
                    "ligne {$function->line} : fonction déjà définie : {$function->name}",
                );
            }

            $params = [];

            foreach ($function->params as $param) {
                if ($param->type === 'void') {
                    throw new CompilerException(
                        "ligne {$param->line} : un paramètre ne peut pas être de type void",
                    );
                }

                if ($param->name === 'this') {
                    throw new CompilerException(
                        "ligne {$param->line} : \$this est réservé aux méthodes",
                    );
                }

                if (isset($params[$param->name])) {
                    throw new CompilerException(
                        "ligne {$param->line} : paramètre dupliqué \${$param->name}",
                    );
                }

                $params[$param->name] = $param->type;
            }

            $functions[$function->name] = new FunctionInfo(
                $function->name,
                $params,
                $function->returnType,
            );
        }

        return $functions;
    }

    /**
     * @return array<string, ClassInfo>
     */
    private function collectClasses(Program $program): array
    {
        $classes = [];

        foreach ($program->classes as $declaration) {
            if (in_array(strtolower($declaration->name), ['array', 'int', 'float', 'bool', 'string', 'void', 'this', 'callable'], true)) {
                throw new CompilerException(
                    "ligne {$declaration->line} : nom de classe réservé : {$declaration->name}",
                );
            }

            if (isset($classes[$declaration->name])) {
                throw new CompilerException(
                    "ligne {$declaration->line} : classe déjà définie : {$declaration->name}",
                );
            }

            $properties = [];

            foreach ($declaration->properties as $property) {
                if (isset($properties[$property->name])) {
                    throw new CompilerException(
                        "ligne {$property->line} : propriété dupliquée \${$property->name}",
                    );
                }

                $properties[$property->name] = $property->type;
            }

            $methods = [];
            $methodDeclarations = [];
            $constructor = null;

            foreach ($declaration->methods as $method) {
                if (isset($methods[$method->name])) {
                    throw new CompilerException(
                        "ligne {$method->line} : méthode dupliquée {$declaration->name}::{$method->name}",
                    );
                }

                $methodDeclarations[$method->name] = $method;

                $params = [];

                foreach ($method->params as $param) {
                    if ($param->type === 'void') {
                        throw new CompilerException(
                            "ligne {$param->line} : un paramètre ne peut pas être de type void",
                        );
                    }

                    if ($param->name === 'this') {
                        throw new CompilerException(
                            "ligne {$param->line} : \$this est réservé aux méthodes",
                        );
                    }

                    if (isset($params[$param->name])) {
                        throw new CompilerException(
                            "ligne {$param->line} : paramètre dupliqué \${$param->name}",
                        );
                    }

                    $params[$param->name] = $param->type;
                }

                if ($method->name === '__construct') {
                    if ($method->returnType !== 'void') {
                        throw new CompilerException(
                            "ligne {$method->line} : __construct doit retourner void",
                        );
                    }

                    $constructor = $method->name;
                }

                $methods[$method->name] = new FunctionInfo(
                    $method->name,
                    $params,
                    $method->returnType,
                );
            }

            $classes[$declaration->name] = new ClassInfo(
                $declaration->name,
                $properties,
                $methods,
                $methodDeclarations,
                $constructor,
            );
        }

        return $classes;
    }

    /**
     * @param list<Stmt> $statements
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeStatements(
        array $statements,
        Scope $scope,
        string $returnType,
        array $functions,
    ): void {
        foreach ($statements as $statement) {
            $this->analyzeStatement($statement, $scope, $returnType, $functions);
        }
    }

    /**
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeStatement(
        Stmt $statement,
        Scope $scope,
        string $returnType,
        array $functions,
    ): void {
        if ($statement instanceof Block) {
            $this->analyzeStatements($statement->statements, $scope, $returnType, $functions);
            return;
        }

        if ($statement instanceof EchoStmt) {
            foreach ($statement->expressions as $expression) {
                $type = $this->analyzeExpr($expression, $scope, $functions);

                if ($type !== 'null' && !$this->isScalar($type)) {
                    throw new CompilerException(
                        "ligne {$expression->line} : echo ne peut pas afficher une valeur de type {$type}",
                    );
                }
            }
            return;
        }

        if ($statement instanceof ReturnStmt) {
            if ($returnType === 'void') {
                if ($statement->value !== null) {
                    throw new CompilerException(
                        "ligne {$statement->line} : une fonction void ne retourne pas de valeur",
                    );
                }
                return;
            }

            if ($statement->value === null) {
                throw new CompilerException(
                    "ligne {$statement->line} : return attendu avec une valeur de type {$returnType}",
                );
            }

            $type = $this->analyzeExpr($statement->value, $scope, $functions);
            $this->requireType($type, $returnType, $statement->line, 'return');

            return;
        }

        if ($statement instanceof IfStmt) {
            $condition = $this->analyzeExpr($statement->condition, $scope, $functions);
            $this->requireType($condition, 'bool', $statement->line, 'condition if');
            $this->analyzeStatement($statement->then, $scope, $returnType, $functions);

            if ($statement->else !== null) {
                $this->analyzeStatement($statement->else, $scope, $returnType, $functions);
            }

            return;
        }

        if ($statement instanceof WhileStmt) {
            $condition = $this->analyzeExpr($statement->condition, $scope, $functions);
            $this->requireType($condition, 'bool', $statement->line, 'condition while');
            $this->analyzeStatement($statement->body, $scope, $returnType, $functions);
            return;
        }

        if ($statement instanceof ForStmt) {
            if ($statement->init !== null) {
                $this->analyzeExpr($statement->init, $scope, $functions);
            }

            if ($statement->condition !== null) {
                $condition = $this->analyzeExpr($statement->condition, $scope, $functions);
                $this->requireType($condition, 'bool', $statement->line, 'condition for');
            }

            if ($statement->step !== null) {
                $this->analyzeExpr($statement->step, $scope, $functions);
            }

            $this->analyzeStatement($statement->body, $scope, $returnType, $functions);
            return;
        }

        if ($statement instanceof ForeachStmt) {
            $iterableType = $this->analyzeExpr($statement->iterable, $scope, $functions);

            if (!$this->isArrayType($iterableType)) {
                throw new CompilerException(
                    "ligne {$statement->line} : foreach attend un tableau, reçu {$iterableType}",
                );
            }

            if ($this->isMapType($iterableType)) {
                $keyType = 'string';
                $elementType = $this->mapValueType($iterableType);
            } elseif ($this->isListType($iterableType)) {
                $keyType = 'int';
                $elementType = $this->arrayElementType($iterableType);
            } else {
                $keyType = null;
                $elementType = null;
            }

            if ($elementType === null || $keyType === null) {
                throw new CompilerException(
                    "ligne {$statement->line} : type d’élément inconnu ; initialisez le tableau avec au moins une valeur",
                );
            }

            if ($statement->keyName !== null) {
                if ($statement->keyName === $statement->valueName) {
                    throw new CompilerException(
                        "ligne {$statement->line} : la clé et la valeur de foreach doivent avoir des noms différents",
                    );
                }

                $existingKey = $scope->typeOf($statement->keyName);

                if ($existingKey !== null && $existingKey !== $keyType) {
                    throw new CompilerException(
                        "ligne {$statement->line} : \${$statement->keyName} est de type {$existingKey}, clé {$keyType} attendue",
                    );
                }

                $scope->define($statement->keyName, $keyType);
            }

            $existingValue = $scope->typeOf($statement->valueName);
            $valueType = $elementType;

            if ($existingValue !== null) {
                if ($existingValue === $elementType
                    || ($existingValue === 'float' && $elementType === 'int')) {
                    $valueType = $existingValue;
                } else {
                    throw new CompilerException(
                        "ligne {$statement->line} : \${$statement->valueName} est de type {$existingValue}, valeur {$elementType} refusée",
                    );
                }
            }

            $scope->define($statement->valueName, $valueType);
            $statement->keyType = $keyType;
            $statement->valueType = $valueType;
            $this->analyzeStatement($statement->body, $scope, $returnType, $functions);
            return;
        }

        if ($statement instanceof SwitchStmt) {
            $subjectType = $this->analyzeExpr($statement->subject, $scope, $functions);

            if (!$this->isScalar($subjectType)) {
                throw new CompilerException(
                    "ligne {$statement->line} : switch attend une valeur scalaire, reçu {$subjectType}",
                );
            }

            $defaults = 0;

            foreach ($statement->cases as $case) {
                if ($case->condition === null) {
                    $defaults++;

                    if ($defaults > 1) {
                        throw new CompilerException(
                            "ligne {$case->line} : plusieurs blocs default dans un switch",
                        );
                    }
                } else {
                    $caseType = $this->analyzeExpr($case->condition, $scope, $functions);
                    $this->requireSwitchComparable($subjectType, $caseType, $case->line);
                }

                $this->breakDepth++;

                try {
                    $this->analyzeStatements($case->statements, $scope, $returnType, $functions);
                } finally {
                    $this->breakDepth--;
                }
            }

            return;
        }

        if ($statement instanceof BreakStmt) {
            if ($this->breakDepth === 0) {
                throw new CompilerException(
                    "ligne {$statement->line} : break autorisé uniquement dans un switch",
                );
            }

            return;
        }

        if ($statement instanceof ThrowStmt) {
            $type = $this->analyzeExpr($statement->value, $scope, $functions);
            $this->requireType($type, 'string', $statement->line, 'throw');

            return;
        }

        if ($statement instanceof TryStmt) {
            if ($statement->catch->type !== 'string') {
                throw new CompilerException(
                    "ligne {$statement->catch->line} : catch attend le type string, reçu {$statement->catch->type}",
                );
            }

            $this->analyzeStatement($statement->body, $scope, $returnType, $functions);
            $existing = $scope->typeOf($statement->catch->variable);

            if ($existing !== null && $existing !== 'string') {
                throw new CompilerException(
                    "ligne {$statement->catch->line} : \${$statement->catch->variable} est de type {$existing}, string attendu",
                );
            }

            $scope->define($statement->catch->variable, 'string');
            $this->analyzeStatement($statement->catch->body, $scope, $returnType, $functions);

            return;
        }

        if ($statement instanceof ExpressionStmt) {
            $this->analyzeExpr($statement->expression, $scope, $functions);
            return;
        }

        throw new CompilerException(
            "ligne {$statement->line} : instruction non supportée",
        );
    }

    /**
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeExpr(Expr $expression, Scope $scope, array $functions): string
    {
        if ($expression instanceof Literal) {
            return $expression->literalType;
        }

        if ($expression instanceof ClosureExpr) {
            $params = [];

            foreach ($expression->params as $param) {
                if ($param->type === 'void') {
                    throw new CompilerException(
                        "ligne {$param->line} : un paramètre de closure ne peut pas être void",
                    );
                }

                if (!in_array($param->type, ['int', 'float', 'bool', 'string'], true)) {
                    throw new CompilerException(
                        "ligne {$param->line} : paramètre de closure non scalaire : {$param->type}",
                    );
                }

                if (isset($params[$param->name])) {
                    throw new CompilerException(
                        "ligne {$param->line} : paramètre dupliqué \${$param->name}",
                    );
                }

                $params[$param->name] = $param->type;
            }

            $captures = [];

            if ($expression->arrow) {
                foreach ($this->freeVariables($expression->expression, $params) as $name) {
                    if ($name === 'this') {
                        throw new CompilerException(
                            "ligne {$expression->line} : \$this ne peut pas être capturé par une arrow function",
                        );
                    }

                    $type = $scope->typeOf($name);

                    if ($type === null) {
                        throw new CompilerException(
                            "ligne {$expression->line} : variable capturée non définie : \${$name}",
                        );
                    }

                    $captures[$name] = $type;
                }
            } else {
                foreach ($expression->use as $name) {
                    if ($name === 'this') {
                        throw new CompilerException(
                            "ligne {$expression->line} : capture de \$this non supportée",
                        );
                    }

                    $type = $scope->typeOf($name);

                    if ($type === null) {
                        throw new CompilerException(
                            "ligne {$expression->line} : variable capturée non définie : \${$name}",
                        );
                    }

                    $captures[$name] = $type;
                }
            }

            foreach ($captures as $name => $_type) {
                if (isset($params[$name])) {
                    throw new CompilerException(
                        "ligne {$expression->line} : \${$name} est à la fois paramètre et capture",
                    );
                }
            }

            $expression->captureTypes = $captures;
            $closureScope = new Scope($params + $captures);
            $returnType = $expression->returnType;
            $savedBreakDepth = $this->breakDepth;
            $this->breakDepth = 0;

            if ($expression->arrow) {
                if ($expression->expression === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : corps de arrow function manquant",
                    );
                }

                $bodyType = $this->analyzeExpr($expression->expression, $closureScope, $functions);

                if ($bodyType === 'void') {
                    if ($returnType !== null && $returnType !== 'void') {
                        throw new CompilerException(
                            "ligne {$expression->line} : retour fn attend {$returnType}, reçu void",
                        );
                    }

                    $returnType = 'void';
                } elseif (!$this->isScalar($bodyType)) {
                    throw new CompilerException(
                        "ligne {$expression->line} : retour de arrow function non scalaire : {$bodyType}",
                    );
                } elseif ($returnType !== null) {
                    $this->requireType($bodyType, $returnType, $expression->line, 'retour fn');
                } else {
                    $returnType = $bodyType;
                }

                $expression->variables = $closureScope->variables;
            } else {
                if ($expression->returnType === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : type de retour requis pour une closure anonyme",
                    );
                }

                if ($expression->returnType !== 'void' && !$this->isScalar($expression->returnType)) {
                    throw new CompilerException(
                        "ligne {$expression->line} : retour de closure non scalaire : {$expression->returnType}",
                    );
                }

                if ($expression->body === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : corps de closure manquant",
                    );
                }

                $this->analyzeStatements(
                    $expression->body->statements,
                    $closureScope,
                    $returnType,
                    $functions,
                );
                $expression->variables = $closureScope->variables;
                $assigned = [];

                foreach ($params as $name => $_type) {
                    $assigned[$name] = true;
                }

                foreach ($captures as $name => $_type) {
                    $assigned[$name] = true;
                }

                [, $returns] = $this->flowStatements($expression->body->statements, $assigned);

                if ($returnType !== 'void' && !$returns) {
                    throw new CompilerException(
                        "ligne {$expression->line} : la closure ne retourne pas sur tous les chemins",
                    );
                }
            }

            $this->breakDepth = $savedBreakDepth;
            $expression->type = $this->callableType(array_values($params), (string) $returnType);

            return $expression->type;
        }

        if ($expression instanceof CallableCallExpr) {
            $calleeType = $this->analyzeExpr($expression->callee, $scope, $functions);

            if (!$this->isCallableType($calleeType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : appel sur {$calleeType}, closure attendue",
                );
            }

            if ($calleeType === 'callable') {
                throw new CompilerException(
                    "ligne {$expression->line} : signature de closure inconnue",
                );
            }

            $signature = $this->callableSignature($calleeType);
            $arguments = array_map(
                fn (Expr $argument): string => $this->analyzeExpr($argument, $scope, $functions),
                $expression->arguments,
            );

            if (count($arguments) !== count($signature['params'])) {
                throw new CompilerException(
                    "ligne {$expression->line} : closure attend "
                    . count($signature['params']) . ' argument(s)',
                );
            }

            foreach ($arguments as $index => $argumentType) {
                $this->requireType(
                    $argumentType,
                    $signature['params'][$index],
                    $expression->line,
                    'appel de closure',
                );
            }

            $expression->type = $signature['return'];

            return $signature['return'];
        }

        if ($expression instanceof NewExpr) {
            if (!isset($this->classes[$expression->className])) {
                throw new CompilerException(
                    "ligne {$expression->line} : classe inconnue : {$expression->className}",
                );
            }

            $class = $this->classes[$expression->className];
            $arguments = array_map(
                fn (Expr $argument): string => $this->analyzeExpr($argument, $scope, $functions),
                $expression->arguments,
            );

            if ($class->constructor === null) {
                if ($arguments !== []) {
                    throw new CompilerException(
                        "ligne {$expression->line} : {$class->name} n’a pas de constructeur",
                    );
                }
            } else {
                $constructor = $class->methods[$class->constructor];
                $parameters = array_values($constructor->params);

                if (count($arguments) !== count($parameters)) {
                    throw new CompilerException(
                        "ligne {$expression->line} : {$class->name}::__construct attend "
                        . count($parameters) . ' argument(s)',
                    );
                }

                foreach ($arguments as $index => $argumentType) {
                    $this->requireType(
                        $argumentType,
                        $parameters[$index],
                        $expression->line,
                        $class->name . '::__construct',
                    );
                }
            }

            $expression->type = $class->name;

            return $class->name;
        }

        if ($expression instanceof PropertyAccessExpr) {
            $objectType = $this->analyzeExpr($expression->object, $scope, $functions);

            if (!$this->isObjectType($objectType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : accès -> sur {$objectType}, objet attendu",
                );
            }

            $class = $this->classes[$objectType];

            if (!isset($class->properties[$expression->property])) {
                throw new CompilerException(
                    "ligne {$expression->line} : propriété inconnue {$objectType}::\${$expression->property}",
                );
            }

            $type = $class->properties[$expression->property];
            $expression->type = $type;

            return $type;
        }

        if ($expression instanceof PropertyAssignExpr) {
            $objectType = $this->analyzeExpr($expression->object, $scope, $functions);

            if (!$this->isObjectType($objectType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : affectation -> sur {$objectType}, objet attendu",
                );
            }

            $class = $this->classes[$objectType];

            if (!isset($class->properties[$expression->property])) {
                throw new CompilerException(
                    "ligne {$expression->line} : propriété inconnue {$objectType}::\${$expression->property}",
                );
            }

            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);
            $propertyType = $class->properties[$expression->property];
            $this->requireType(
                $valueType,
                $propertyType,
                $expression->line,
                $objectType . '::$' . $expression->property,
            );
            $expression->type = $propertyType;

            return $propertyType;
        }

        if ($expression instanceof MethodCallExpr) {
            $objectType = $this->analyzeExpr($expression->object, $scope, $functions);

            if (!$this->isObjectType($objectType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : appel de méthode sur {$objectType}, objet attendu",
                );
            }

            $class = $this->classes[$objectType];

            if (!isset($class->methods[$expression->method])) {
                throw new CompilerException(
                    "ligne {$expression->line} : méthode inconnue {$objectType}::{$expression->method}",
                );
            }

            $method = $class->methods[$expression->method];
            $parameters = array_values($method->params);
            $arguments = array_map(
                fn (Expr $argument): string => $this->analyzeExpr($argument, $scope, $functions),
                $expression->arguments,
            );

            if (count($arguments) !== count($parameters)) {
                throw new CompilerException(
                    "ligne {$expression->line} : {$objectType}::{$expression->method} attend "
                    . count($parameters) . ' argument(s)',
                );
            }

            foreach ($arguments as $index => $argumentType) {
                $this->requireType(
                    $argumentType,
                    $parameters[$index],
                    $expression->line,
                    $objectType . '::' . $expression->method,
                );
            }

            $expression->type = $method->returnType;

            return $method->returnType;
        }

        if ($expression instanceof MatchExpr) {
            $subjectType = $this->analyzeExpr($expression->subject, $scope, $functions);

            if (!$this->isScalar($subjectType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : match attend une valeur scalaire, reçu {$subjectType}",
                );
            }

            $defaults = 0;
            $resultType = null;

            foreach ($expression->arms as $arm) {
                if ($arm->conditions === []) {
                    $defaults++;

                    if ($defaults > 1) {
                        throw new CompilerException(
                            "ligne {$arm->line} : plusieurs branches default dans un match",
                        );
                    }
                } else {
                    foreach ($arm->conditions as $condition) {
                        $conditionType = $this->analyzeExpr($condition, $scope, $functions);

                        if ($conditionType !== $subjectType) {
                            throw new CompilerException(
                                "ligne {$condition->line} : condition match de type {$conditionType}, sujet {$subjectType}",
                            );
                        }
                    }
                }

                $bodyType = $this->analyzeExpr($arm->body, $scope, $functions);

                if (!$this->isScalar($bodyType)) {
                    throw new CompilerException(
                        "ligne {$arm->body->line} : résultat match non scalaire : {$bodyType}",
                    );
                }

                if ($resultType === null) {
                    $resultType = $bodyType;
                } elseif ($resultType !== $bodyType) {
                    if ($this->isNumeric($resultType) && $this->isNumeric($bodyType)) {
                        $resultType = 'float';
                    } else {
                        throw new CompilerException(
                            "ligne {$arm->body->line} : types de branches match incompatibles : {$resultType} et {$bodyType}",
                        );
                    }
                }
            }

            if ($resultType === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : match sans branche",
                );
            }

            if ($defaults === 0) {
                throw new CompilerException(
                    "ligne {$expression->line} : match doit avoir une branche default",
                );
            }

            $expression->type = $resultType;

            return $resultType;
        }

        if ($expression instanceof VariableExpr) {
            $type = $scope->typeOf($expression->name);

            if ($type === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : variable non définie : \${$expression->name}",
                );
            }

            if ($type === 'array') {
                throw new CompilerException(
                    "ligne {$expression->line} : type de tableau inconnu pour \${$expression->name} ; initialisez-le avec une valeur indexée ou une clé",
                );
            }

            $expression->type = $type;

            return $type;
        }

        if ($expression instanceof AssignExpr) {
            if ($expression->name === 'this') {
                throw new CompilerException(
                    "ligne {$expression->line} : affectation à \$this interdite",
                );
            }

            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);

            if ($valueType === 'void' || $valueType === 'null') {
                throw new CompilerException(
                    "ligne {$expression->line} : valeur non stockable dans \${$expression->name}",
                );
            }

            if ($scope->has($expression->name)) {
                $existing = (string) $scope->typeOf($expression->name);
                $result = $this->assignType($existing, $valueType, $expression->line, $expression->name);

                if ($result !== $existing) {
                    $scope->define($expression->name, $result);
                }
            } else {
                $scope->define($expression->name, $valueType);
            }

            $expression->type = (string) $scope->typeOf($expression->name);

            return $expression->type;
        }

        if ($expression instanceof ArrayExpr) {
            $elementType = null;
            $hasKeys = false;
            $hasValues = false;

            foreach ($expression->elements as $item) {
                $type = $this->analyzeExpr($item->value, $scope, $functions);

                if ($this->isArrayType($type) || $this->isObjectType($type) || $this->isCallableType($type) || $type === 'void' || $type === 'null') {
                    throw new CompilerException(
                        "ligne {$item->value->line} : élément de tableau non scalaire : {$type}",
                    );
                }

                if ($item->key !== null) {
                    $hasKeys = true;
                    $keyType = $this->analyzeExpr($item->key, $scope, $functions);
                    $this->requireType($keyType, 'string', $item->line, 'clé associative');
                } else {
                    $hasValues = true;
                }

                $elementType = $this->mergeElementType($elementType, $type, $expression->line);
            }

            if ($hasKeys && $hasValues) {
                throw new CompilerException(
                    "ligne {$expression->line} : tableau mélangeant index et clés associatives",
                );
            }

            if ($hasKeys) {
                $expression->type = $elementType === null ? 'array' : $this->mapType($elementType);
            } else {
                $expression->type = $elementType === null ? 'array' : $this->arrayType($elementType);
            }

            return $expression->type;
        }

        if ($expression instanceof IndexExpr) {
            $arrayType = $this->analyzeExpr($expression->array, $scope, $functions);

            if (!$this->isArrayType($arrayType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : indexation sur {$arrayType}, tableau attendu",
                );
            }

            if ($expression->index === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : index manquant en lecture",
                );
            }

            $indexType = $this->analyzeExpr($expression->index, $scope, $functions);

            if ($this->isMapType($arrayType)) {
                $this->requireType($indexType, 'string', $expression->line, 'clé associative');
                $elementType = $this->mapValueType($arrayType);
            } elseif ($this->isListType($arrayType)) {
                $this->requireType($indexType, 'int', $expression->line, 'index de tableau');
                $elementType = $this->arrayElementType($arrayType);
            } else {
                throw new CompilerException(
                    "ligne {$expression->line} : type de tableau inconnu ; initialisez-le avec au moins une valeur",
                );
            }

            if ($elementType === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : type d’élément inconnu ; initialisez le tableau avec au moins une valeur",
                );
            }

            $expression->type = $elementType;

            return $elementType;
        }

        if ($expression instanceof AppendExpr) {
            $arrayType = $this->analyzeArrayTarget($expression->array, $scope, $expression->line);

            if ($this->isMapType($arrayType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : ajout [] interdit sur un tableau associatif",
                );
            }

            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);

            if ($this->isArrayType($valueType) || $this->isObjectType($valueType) || $this->isCallableType($valueType) || $valueType === 'void' || $valueType === 'null') {
                throw new CompilerException(
                    "ligne {$expression->line} : valeur de tableau non scalaire : {$valueType}",
                );
            }

            if ($arrayType === 'array') {
                $arrayType = $this->arrayType($valueType);
                $scope->define($expression->array->name, $arrayType);
                $expression->array->type = $arrayType;
            }

            $elementType = $this->arrayElementType($arrayType);
            $this->requireType($valueType, (string) $elementType, $expression->line, 'ajout de tableau');
            $expression->type = $elementType;

            return $elementType;
        }

        if ($expression instanceof IndexAssignExpr) {
            $arrayType = $this->analyzeArrayTarget($expression->array, $scope, $expression->line);

            $indexType = $this->analyzeExpr($expression->index, $scope, $functions);
            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);

            if ($this->isArrayType($valueType) || $this->isObjectType($valueType) || $this->isCallableType($valueType) || $valueType === 'void' || $valueType === 'null') {
                throw new CompilerException(
                    "ligne {$expression->line} : valeur de tableau non scalaire : {$valueType}",
                );
            }

            if ($arrayType === 'array') {
                if ($indexType === 'string') {
                    $arrayType = $this->mapType($valueType);
                } elseif ($indexType === 'int') {
                    $arrayType = $this->arrayType($valueType);
                } else {
                    throw new CompilerException(
                        "ligne {$expression->line} : clé de type {$indexType} non supportée ; utilisez int ou string",
                    );
                }

                $scope->define($expression->array->name, $arrayType);
                $expression->array->type = $arrayType;
            }

            if ($this->isMapType($arrayType)) {
                $this->requireType($indexType, 'string', $expression->line, 'clé associative');
                $elementType = $this->mapValueType($arrayType);
            } elseif ($this->isListType($arrayType)) {
                $this->requireType($indexType, 'int', $expression->line, 'index de tableau');
                $elementType = $this->arrayElementType($arrayType);
            } else {
                throw new CompilerException(
                    "ligne {$expression->line} : type de tableau inconnu ; initialisez-le avec au moins une valeur",
                );
            }

            $this->requireType($valueType, (string) $elementType, $expression->line, 'affectation de tableau');
            $expression->type = $elementType;

            return $elementType;
        }

        if ($expression instanceof BinaryExpr) {
            return $this->analyzeBinary($expression, $scope, $functions);
        }

        if ($expression instanceof UnaryExpr) {
            return $this->analyzeUnary($expression, $scope, $functions);
        }

        if ($expression instanceof CallExpr) {
            return $this->analyzeCall($expression, $scope, $functions);
        }

        throw new CompilerException(
            "ligne {$expression->line} : expression non supportée",
        );
    }

    private function analyzeArrayTarget(VariableExpr $target, Scope $scope, int $line): string
    {
        $type = $scope->typeOf($target->name);

        if ($type === null) {
            throw new CompilerException(
                "ligne {$line} : variable non définie : \${$target->name}",
            );
        }

        $target->type = $type;

        if (!$this->isArrayType($type)) {
            throw new CompilerException(
                "ligne {$line} : \${$target->name} est de type {$type}, tableau attendu",
            );
        }

        return $type;
    }

    /**
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeBinary(BinaryExpr $expression, Scope $scope, array $functions): string
    {
        $left = $this->analyzeExpr($expression->left, $scope, $functions);
        $right = $this->analyzeExpr($expression->right, $scope, $functions);
        $operator = $expression->operator;

        if ($this->isArrayType($left) || $this->isArrayType($right)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur un tableau",
            );
        }

        if ($this->isObjectType($left) || $this->isObjectType($right)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur un objet",
            );
        }

        if ($this->isCallableType($left) || $this->isCallableType($right)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur une closure",
            );
        }

        $type = match ($operator) {
            '+', '-', '*' => $this->numericResult($left, $right, $expression->line, $operator),
            '/' => $this->floatDivision($left, $right, $expression->line),
            '%' => $this->modulo($left, $right, $expression->line),
            '.' => $this->concatenation($left, $right, $expression->line),
            '<', '<=', '>', '>=' => $this->comparison($left, $right, $expression->line, $operator),
            '==', '!=', '===', '!==' => $this->equality($left, $right, $expression->line, $operator),
            '&&', '||' => $this->logical($left, $right, $expression->line, $operator),
            default => throw new CompilerException(
                "ligne {$expression->line} : opérateur non supporté : {$operator}",
            ),
        };

        $expression->type = $type;

        return $type;
    }

    /**
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeUnary(UnaryExpr $expression, Scope $scope, array $functions): string
    {
        $operand = $this->analyzeExpr($expression->operand, $scope, $functions);
        $operator = $expression->operator;

        if ($this->isArrayType($operand)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur un tableau",
            );
        }

        if ($this->isObjectType($operand)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur un objet",
            );
        }

        if ($this->isCallableType($operand)) {
            throw new CompilerException(
                "ligne {$expression->line} : opérateur {$operator} non supporté sur une closure",
            );
        }

        if ($operator === '!') {
            $this->requireType($operand, 'bool', $expression->line, 'opérateur !');
            $expression->type = 'bool';
            return 'bool';
        }

        if ($operator === '-' || $operator === '+') {
            if (!$this->isNumeric($operand)) {
                throw new CompilerException(
                    "ligne {$expression->line} : {$operator} attend un opérande numérique, reçu {$operand}",
                );
            }

            $expression->type = $operand;
            return $operand;
        }

        throw new CompilerException(
            "ligne {$expression->line} : opérateur unaire non supporté : {$operator}",
        );
    }

    /**
     * @param array<string, FunctionInfo> $functions
     */
    private function analyzeCall(CallExpr $expression, Scope $scope, array $functions): string
    {
        if ($expression->name === 'isset') {
            if (count($expression->arguments) !== 1) {
                throw new CompilerException(
                    "ligne {$expression->line} : isset attend exactement un accès à un tableau",
                );
            }

            $argument = $expression->arguments[0];

            if (!$argument instanceof IndexExpr || $argument->index === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : isset attend une expression de la forme \$tableau[\$clé]",
                );
            }

            $arrayType = $this->analyzeExpr($argument->array, $scope, $functions);

            if (!$this->isArrayType($arrayType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : isset attend un tableau, reçu {$arrayType}",
                );
            }

            $keyType = $this->analyzeExpr($argument->index, $scope, $functions);

            if ($this->isMapType($arrayType)) {
                $this->requireType($keyType, 'string', $expression->line, 'isset (clé associative)');
            } elseif ($this->isListType($arrayType)) {
                $this->requireType($keyType, 'int', $expression->line, 'isset (index de tableau)');
            } else {
                throw new CompilerException(
                    "ligne {$expression->line} : type de tableau inconnu pour isset",
                );
            }

            $expression->type = 'bool';

            return 'bool';
        }

        $builtin = $this->builtin($expression->name);

        if ($builtin !== null) {
            $arguments = array_map(
                fn (Expr $argument): string => $this->analyzeExpr($argument, $scope, $functions),
                $expression->arguments,
            );

            $expectedCount = count($builtin['params']);
            $givenCount = count($arguments);
            $optionalData = in_array(
                $expression->name,
                ['ui_on_click', 'ui_on_activate', 'ui_after'],
                true,
            );

            if ($optionalData
                ? ($givenCount !== $expectedCount && $givenCount !== $expectedCount + 1)
                : $givenCount !== $expectedCount) {
                throw new CompilerException(
                    "ligne {$expression->line} : {$expression->name} attend "
                    . ($optionalData ? '2 ou 3' : $expectedCount) . ' argument(s)',
                );
            }

            if ($optionalData && $givenCount === $expectedCount + 1) {
                $this->requireType(
                    $arguments[$expectedCount],
                    'int',
                    $expression->line,
                    $expression->name . ' (handle de données)',
                );
            }

            foreach ($builtin['params'] as $index => $expected) {
                if ($expected === 'scalar') {
                    $this->requireScalar($arguments[$index], $expression->line, $expression->name);
                    continue;
                }

                if ($expected === 'numeric') {
                    if (!$this->isNumeric($arguments[$index])) {
                        throw new CompilerException(
                            "ligne {$expression->line} : {$expression->name} attend une valeur numérique, reçu {$arguments[$index]}",
                        );
                    }
                    continue;
                }

                if ($expected === 'array') {
                    if (!$this->isArrayType($arguments[$index])) {
                        throw new CompilerException(
                            "ligne {$expression->line} : {$expression->name} attend un tableau, reçu {$arguments[$index]}",
                        );
                    }
                    continue;
                }

                $this->requireType($arguments[$index], $expected, $expression->line, $expression->name);
            }

            if (in_array($expression->name, ['ui_on_click', 'ui_on_activate', 'ui_after'], true)) {
                $handler = $expression->arguments[1] ?? null;

                if (!$handler instanceof Literal || $handler->literalType !== 'string') {
                    throw new CompilerException(
                        "ligne {$expression->line} : {$expression->name} attend un nom de fonction littéral",
                    );
                }

                $handlerName = (string) $handler->value;

                if (!isset($functions[$handlerName])) {
                    throw new CompilerException(
                        "ligne {$handler->line} : gestionnaire inconnu : {$handlerName}",
                    );
                }

                $handlerInfo = $functions[$handlerName];
                $handlerParams = array_values($handlerInfo->params);

                if ($handlerInfo->returnType !== 'void'
                    || !($handlerParams === []
                        || (count($handlerParams) === 1 && $handlerParams[0] === 'int'))) {
                    throw new CompilerException(
                        "ligne {$handler->line} : le gestionnaire {$handlerName} doit être déclaré function {$handlerName}(): void ou function {$handlerName}(int \$handle): void",
                    );
                }
            }

            if (in_array(
                $expression->name,
                ['ui_on_click_closure', 'ui_on_activate_closure', 'ui_after_closure'],
                true,
            )) {
                $callableType = $arguments[1] ?? null;

                if ($callableType === null
                    || !$this->isCallableType($callableType)
                    || $callableType === 'callable') {
                    throw new CompilerException(
                        "ligne {$expression->line} : {$expression->name} attend callable():void",
                    );
                }

                $signature = $this->callableSignature($callableType);

                if ($signature['params'] !== [] || $signature['return'] !== 'void') {
                    throw new CompilerException(
                        "ligne {$expression->line} : {$expression->name} attend callable():void",
                    );
                }
            }

            $return = $builtin['return'] === 'same'
                ? $arguments[0]
                : $builtin['return'];
            $expression->type = $return;

            return $expression->type;
        }

        if (!isset($functions[$expression->name])) {
            throw new CompilerException(
                "ligne {$expression->line} : fonction inconnue : {$expression->name}",
            );
        }

        $function = $functions[$expression->name];
        $parameters = array_values($function->params);

        if (count($expression->arguments) !== count($parameters)) {
            throw new CompilerException(
                "ligne {$expression->line} : {$expression->name} attend "
                . count($parameters) . ' argument(s), reçu ' . count($expression->arguments),
            );
        }

        foreach ($expression->arguments as $index => $argument) {
            $type = $this->analyzeExpr($argument, $scope, $functions);
            $this->requireType($type, $parameters[$index], $expression->line, $expression->name);
        }

        $expression->type = $function->returnType;

        return $expression->type;
    }

    /**
     * @param list<Stmt> $statements
     * @param array<string, true> $assigned
     * @return array{0: array<string, true>, 1: bool}
     */
    private function flowStatements(array $statements, array $assigned): array
    {
        foreach ($statements as $statement) {
            [$assigned, $returns] = $this->flowStatement($statement, $assigned);

            if ($returns) {
                return [$assigned, true];
            }
        }

        return [$assigned, false];
    }

    /**
     * @param array<string, true> $assigned
     * @return array{0: array<string, true>, 1: bool}
     */
    private function flowStatement(Stmt $statement, array $assigned): array
    {
        if ($statement instanceof Block) {
            return $this->flowStatements($statement->statements, $assigned);
        }

        if ($statement instanceof EchoStmt) {
            foreach ($statement->expressions as $expression) {
                $assigned = $this->flowExpr($expression, $assigned);
            }

            return [$assigned, false];
        }

        if ($statement instanceof ReturnStmt) {
            if ($statement->value !== null) {
                $assigned = $this->flowExpr($statement->value, $assigned);
            }

            return [$assigned, true];
        }

        if ($statement instanceof ExpressionStmt) {
            $assigned = $this->flowExpr($statement->expression, $assigned);

            return [$assigned, false];
        }

        if ($statement instanceof IfStmt) {
            $assigned = $this->flowExpr($statement->condition, $assigned);
            [$thenAssigned, $thenReturns] = $this->flowStatement($statement->then, $assigned);

            if ($statement->else !== null) {
                [$elseAssigned, $elseReturns] = $this->flowStatement($statement->else, $assigned);
            } else {
                $elseAssigned = $assigned;
                $elseReturns = false;
            }

            return [
                array_intersect_key($thenAssigned, $elseAssigned),
                $thenReturns && $elseReturns,
            ];
        }

        if ($statement instanceof WhileStmt) {
            $assigned = $this->flowExpr($statement->condition, $assigned);
            $this->flowStatement($statement->body, $assigned);

            return [$assigned, false];
        }

        if ($statement instanceof ForStmt) {
            if ($statement->init !== null) {
                $assigned = $this->flowExpr($statement->init, $assigned);
            }

            if ($statement->condition !== null) {
                $assigned = $this->flowExpr($statement->condition, $assigned);
            }

            [$bodyAssigned] = $this->flowStatement($statement->body, $assigned);

            if ($statement->step !== null) {
                $this->flowExpr($statement->step, $bodyAssigned);
            }

            return [$assigned, false];
        }

        if ($statement instanceof ForeachStmt) {
            $assigned = $this->flowExpr($statement->iterable, $assigned);
            $bodyAssigned = $assigned;

            if ($statement->keyName !== null) {
                $bodyAssigned[$statement->keyName] = true;
            }

            $bodyAssigned[$statement->valueName] = true;
            $this->flowStatement($statement->body, $bodyAssigned);

            return [$assigned, false];
        }

        if ($statement instanceof SwitchStmt) {
            $assigned = $this->flowExpr($statement->subject, $assigned);
            $hasDefault = false;
            $allReturn = true;
            $nextReturns = false;

            for ($i = count($statement->cases) - 1; $i >= 0; $i--) {
                $case = $statement->cases[$i];

                if ($case->condition === null) {
                    $hasDefault = true;
                }

                $caseAssigned = $assigned;

                if ($case->condition !== null) {
                    $caseAssigned = $this->flowExpr($case->condition, $caseAssigned);
                }

                [, $bodyReturns] = $this->flowStatements($case->statements, $caseAssigned);
                $caseReturns = $bodyReturns;

                if (!$caseReturns) {
                    $caseReturns = $this->endsWithBreak($case->statements)
                        ? false
                        : $nextReturns;
                }

                if (!$caseReturns) {
                    $allReturn = false;
                }

                $nextReturns = $caseReturns;
            }

            return [$assigned, $hasDefault && $allReturn];
        }

        if ($statement instanceof BreakStmt) {
            return [$assigned, false];
        }

        if ($statement instanceof ThrowStmt) {
            $assigned = $this->flowExpr($statement->value, $assigned);

            return [$assigned, true];
        }

        if ($statement instanceof TryStmt) {
            $before = $assigned;
            [$tryAssigned, $tryReturns] = $this->flowStatements(
                $statement->body->statements,
                $before,
            );
            $catchAssigned = $before;
            $catchAssigned[$statement->catch->variable] = true;
            [$catchAssigned, $catchReturns] = $this->flowStatements(
                $statement->catch->body->statements,
                $catchAssigned,
            );

            return [
                array_intersect_key($tryAssigned, $catchAssigned),
                $tryReturns && $catchReturns,
            ];
        }

        throw new CompilerException(
            "ligne {$statement->line} : instruction non supportée par l’analyse de flux",
        );
    }

    /**
     * @param array<string, true> $assigned
     * @return array<string, true>
     */
    private function flowExpr(Expr $expression, array $assigned): array
    {
        if ($expression instanceof Literal) {
            return $assigned;
        }

        if ($expression instanceof MatchExpr) {
            $assigned = $this->flowExpr($expression->subject, $assigned);

            foreach ($expression->arms as $arm) {
                $armAssigned = $assigned;

                foreach ($arm->conditions as $condition) {
                    $armAssigned = $this->flowExpr($condition, $armAssigned);
                }

                $this->flowExpr($arm->body, $armAssigned);
            }

            return $assigned;
        }

        if ($expression instanceof NewExpr) {
            foreach ($expression->arguments as $argument) {
                $assigned = $this->flowExpr($argument, $assigned);
            }

            return $assigned;
        }

        if ($expression instanceof ClosureExpr) {
            foreach ($expression->captureTypes as $name => $_type) {
                if (!isset($assigned[$name])) {
                    throw new CompilerException(
                        "ligne {$expression->line} : \${$name} capturée mais peut ne pas être affectée",
                    );
                }
            }

            return $assigned;
        }

        if ($expression instanceof CallableCallExpr) {
            $assigned = $this->flowExpr($expression->callee, $assigned);

            foreach ($expression->arguments as $argument) {
                $assigned = $this->flowExpr($argument, $assigned);
            }

            return $assigned;
        }

        if ($expression instanceof PropertyAccessExpr) {
            return $this->flowExpr($expression->object, $assigned);
        }

        if ($expression instanceof PropertyAssignExpr) {
            $assigned = $this->flowExpr($expression->object, $assigned);

            return $this->flowExpr($expression->value, $assigned);
        }

        if ($expression instanceof MethodCallExpr) {
            $assigned = $this->flowExpr($expression->object, $assigned);

            foreach ($expression->arguments as $argument) {
                $assigned = $this->flowExpr($argument, $assigned);
            }

            return $assigned;
        }

        if ($expression instanceof VariableExpr) {
            if (!isset($assigned[$expression->name])) {
                throw new CompilerException(
                    "ligne {$expression->line} : \${$expression->name} peut ne pas être affectée sur tous les chemins",
                );
            }

            return $assigned;
        }

        if ($expression instanceof AssignExpr) {
            $assigned = $this->flowExpr($expression->value, $assigned);
            $assigned[$expression->name] = true;

            return $assigned;
        }

        if ($expression instanceof ArrayExpr) {
            foreach ($expression->elements as $item) {
                if ($item->key !== null) {
                    $assigned = $this->flowExpr($item->key, $assigned);
                }

                $assigned = $this->flowExpr($item->value, $assigned);
            }

            return $assigned;
        }

        if ($expression instanceof IndexExpr) {
            $assigned = $this->flowExpr($expression->array, $assigned);

            if ($expression->index !== null) {
                $assigned = $this->flowExpr($expression->index, $assigned);
            }

            return $assigned;
        }

        if ($expression instanceof AppendExpr) {
            $assigned = $this->flowExpr($expression->array, $assigned);

            return $this->flowExpr($expression->value, $assigned);
        }

        if ($expression instanceof IndexAssignExpr) {
            $assigned = $this->flowExpr($expression->array, $assigned);
            $assigned = $this->flowExpr($expression->index, $assigned);

            return $this->flowExpr($expression->value, $assigned);
        }

        if ($expression instanceof BinaryExpr) {
            $assigned = $this->flowExpr($expression->left, $assigned);

            if ($expression->operator === '&&' || $expression->operator === '||') {
                $copy = $assigned;
                $this->flowExpr($expression->right, $copy);

                return $assigned;
            }

            return $this->flowExpr($expression->right, $assigned);
        }

        if ($expression instanceof UnaryExpr) {
            return $this->flowExpr($expression->operand, $assigned);
        }

        if ($expression instanceof CallExpr) {
            foreach ($expression->arguments as $argument) {
                $assigned = $this->flowExpr($argument, $assigned);
            }

            return $assigned;
        }

        throw new CompilerException(
            "ligne {$expression->line} : expression non supportée par l’analyse de flux",
        );
    }

    /** @param list<Stmt> $statements */
    private function endsWithBreak(array $statements): bool
    {
        $last = $statements === [] ? null : $statements[count($statements) - 1];

        if ($last instanceof BreakStmt) {
            return true;
        }

        if ($last instanceof Block) {
            return $this->endsWithBreak($last->statements);
        }

        return false;
    }

    private function numericResult(string $left, string $right, int $line, string $operator): string
    {
        if (!$this->isNumeric($left) || !$this->isNumeric($right)) {
            throw new CompilerException(
                "ligne {$line} : {$operator} attend des opérandes numériques, reçu {$left} et {$right}",
            );
        }

        return $left === 'float' || $right === 'float' ? 'float' : 'int';
    }

    private function floatDivision(string $left, string $right, int $line): string
    {
        if (!$this->isNumeric($left) || !$this->isNumeric($right)) {
            throw new CompilerException(
                "ligne {$line} : / attend des opérandes numériques, reçu {$left} et {$right}",
            );
        }

        return 'float';
    }

    private function modulo(string $left, string $right, int $line): string
    {
        if ($left !== 'int' || $right !== 'int') {
            throw new CompilerException(
                "ligne {$line} : % attend deux entiers, reçu {$left} et {$right}",
            );
        }

        return 'int';
    }

    private function concatenation(string $left, string $right, int $line): string
    {
        $this->requireScalar($left, $line, '.');
        $this->requireScalar($right, $line, '.');

        return 'string';
    }

    private function comparison(string $left, string $right, int $line, string $operator): string
    {
        if (!$this->isNumeric($left) || !$this->isNumeric($right)) {
            throw new CompilerException(
                "ligne {$line} : {$operator} attend des opérandes numériques, reçu {$left} et {$right}",
            );
        }

        return 'bool';
    }

    private function equality(string $left, string $right, int $line, string $operator): string
    {
        if ($operator === '===' || $operator === '!==') {
            if ($left !== $right) {
                throw new CompilerException(
                    "ligne {$line} : {$operator} attend deux types identiques, reçu {$left} et {$right}",
                );
            }

            return 'bool';
        }

        if ($left === $right || ($this->isNumeric($left) && $this->isNumeric($right))) {
            return 'bool';
        }

        throw new CompilerException(
            "ligne {$line} : {$operator} attend des types compatibles, reçu {$left} et {$right}",
        );
    }

    private function logical(string $left, string $right, int $line, string $operator): string
    {
        if ($left !== 'bool' || $right !== 'bool') {
            throw new CompilerException(
                "ligne {$line} : {$operator} attend deux booléens, reçu {$left} et {$right}",
            );
        }

        return 'bool';
    }

    private function requireType(string $actual, string $expected, int $line, string $context): void
    {
        if ($actual === $expected || ($expected === 'float' && $actual === 'int')) {
            return;
        }

        if ($expected === 'callable' && $this->isCallableType($actual)) {
            return;
        }

        throw new CompilerException(
            "ligne {$line} : {$context} attend {$expected}, reçu {$actual}",
        );
    }

    private function requireSwitchComparable(string $subject, string $case, int $line): void
    {
        if ($subject === $case || ($this->isNumeric($subject) && $this->isNumeric($case))) {
            return;
        }

        throw new CompilerException(
            "ligne {$line} : case de type {$case} incompatible avec switch {$subject}",
        );
    }

    private function requireScalar(string $type, int $line, string $context): void
    {
        if ($this->isScalar($type)) {
            return;
        }

        throw new CompilerException(
            "ligne {$line} : {$context} attend une valeur scalaire, reçu {$type}",
        );
    }

    private function assignType(string $existing, string $valueType, int $line, string $name): string
    {
        if ($existing === 'callable') {
            if ($this->isCallableType($valueType)) {
                return 'callable';
            }

            throw new CompilerException(
                "ligne {$line} : \${$name} est de type callable, valeur {$valueType} refusée",
            );
        }

        if ($this->isArrayType($existing)) {
            if (!$this->isArrayType($valueType)) {
                throw new CompilerException(
                    "ligne {$line} : \${$name} est un tableau, valeur {$valueType} refusée",
                );
            }

            if ($existing === 'array') {
                return $valueType;
            }

            if ($valueType === 'array' || $valueType === $existing) {
                return $existing;
            }

            throw new CompilerException(
                "ligne {$line} : \${$name} est de type {$existing}, valeur {$valueType} refusée",
            );
        }

        if ($this->isArrayType($valueType)) {
            throw new CompilerException(
                "ligne {$line} : \${$name} est de type {$existing}, tableau {$valueType} refusé",
            );
        }

        if ($existing === $valueType) {
            return $existing;
        }

        if ($existing === 'float' && $valueType === 'int') {
            return 'float';
        }

        throw new CompilerException(
            "ligne {$line} : \${$name} est de type {$existing}, valeur {$valueType} refusée",
        );
    }

    private function mergeElementType(?string $current, string $type, int $line): string
    {
        if ($current === null) {
            return $type;
        }

        if ($current === $type) {
            return $current;
        }

        if ($this->isNumeric($current) && $this->isNumeric($type)) {
            return 'float';
        }

        throw new CompilerException(
            "ligne {$line} : tableau hétérogène : {$current} et {$type}",
        );
    }

    private function isListType(string $type): bool
    {
        return $type === 'array'
            || preg_match('/^array<(int|float|bool|string)>$/', $type) === 1;
    }

    private function isMapType(string $type): bool
    {
        return preg_match('/^array<string,(int|float|bool|string)>$/', $type) === 1;
    }

    private function isArrayType(string $type): bool
    {
        return $this->isListType($type) || $this->isMapType($type);
    }

    private function arrayElementType(string $type): ?string
    {
        if (preg_match('/^array<(int|float|bool|string)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function mapValueType(string $type): ?string
    {
        if (preg_match('/^array<string,(int|float|bool|string)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function arrayType(string $element): string
    {
        return 'array<' . $element . '>';
    }

    private function mapType(string $value): string
    {
        return 'array<string,' . $value . '>';
    }

    private function isNumeric(string $type): bool
    {
        return $type === 'int' || $type === 'float';
    }

    private function isScalar(string $type): bool
    {
        return in_array($type, ['int', 'float', 'bool', 'string'], true);
    }

    private function isObjectType(string $type): bool
    {
        return isset($this->classes[$type]);
    }

    private function isCallableType(string $type): bool
    {
        return $type === 'callable' || preg_match(
            '/^callable\(([^)]*)\):(int|float|bool|string|void)$/',
            $type,
        ) === 1;
    }

    /** @param list<string> $params */
    private function callableType(array $params, string $return): string
    {
        return 'callable(' . implode(',', $params) . '):' . $return;
    }

    /** @return array{params: list<string>, return: string} */
    private function callableSignature(string $type): array
    {
        if (preg_match(
            '/^callable\(([^)]*)\):(int|float|bool|string|void)$/',
            $type,
            $matches,
        ) !== 1) {
            throw new CompilerException('signature callable invalide : ' . $type);
        }

        $params = $matches[1] === '' ? [] : explode(',', $matches[1]);

        foreach ($params as $param) {
            if (!in_array($param, ['int', 'float', 'bool', 'string'], true)) {
                throw new CompilerException(
                    'paramètre callable non supporté : ' . $param,
                );
            }
        }

        return ['params' => $params, 'return' => $matches[2]];
    }

    /**
     * @param array<string, string> $params
     * @return list<string>
     */
    private function freeVariables(?Expr $expression, array $params): array
    {
        if ($expression === null) {
            return [];
        }

        $free = [];
        $this->collectFreeVariables($expression, $params, $free);

        return array_keys($free);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, true> $free
     */
    private function collectFreeVariables(Expr $expression, array $params, array &$free): void
    {
        if ($expression instanceof VariableExpr) {
            if ($expression->name !== 'this' && !isset($params[$expression->name])) {
                $free[$expression->name] = true;
            }
            return;
        }

        if ($expression instanceof Literal) {
            return;
        }

        if ($expression instanceof ClosureExpr) {
            return;
        }

        if ($expression instanceof AssignExpr) {
            if ($expression->name !== 'this' && !isset($params[$expression->name])) {
                $free[$expression->name] = true;
            }
            $this->collectFreeVariables($expression->value, $params, $free);
            return;
        }

        if ($expression instanceof ArrayExpr) {
            foreach ($expression->elements as $item) {
                if ($item->key !== null) {
                    $this->collectFreeVariables($item->key, $params, $free);
                }
                $this->collectFreeVariables($item->value, $params, $free);
            }
            return;
        }

        if ($expression instanceof IndexExpr) {
            $this->collectFreeVariables($expression->array, $params, $free);

            if ($expression->index !== null) {
                $this->collectFreeVariables($expression->index, $params, $free);
            }
            return;
        }

        if ($expression instanceof IndexAssignExpr) {
            $this->collectFreeVariables($expression->array, $params, $free);
            $this->collectFreeVariables($expression->index, $params, $free);
            $this->collectFreeVariables($expression->value, $params, $free);
            return;
        }

        if ($expression instanceof AppendExpr) {
            $this->collectFreeVariables($expression->array, $params, $free);
            $this->collectFreeVariables($expression->value, $params, $free);
            return;
        }

        if ($expression instanceof BinaryExpr) {
            $this->collectFreeVariables($expression->left, $params, $free);
            $this->collectFreeVariables($expression->right, $params, $free);
            return;
        }

        if ($expression instanceof UnaryExpr) {
            $this->collectFreeVariables($expression->operand, $params, $free);
            return;
        }

        if ($expression instanceof CallExpr) {
            foreach ($expression->arguments as $argument) {
                $this->collectFreeVariables($argument, $params, $free);
            }
            return;
        }

        if ($expression instanceof CallableCallExpr) {
            $this->collectFreeVariables($expression->callee, $params, $free);

            foreach ($expression->arguments as $argument) {
                $this->collectFreeVariables($argument, $params, $free);
            }
            return;
        }

        if ($expression instanceof MethodCallExpr) {
            $this->collectFreeVariables($expression->object, $params, $free);

            foreach ($expression->arguments as $argument) {
                $this->collectFreeVariables($argument, $params, $free);
            }
            return;
        }

        if ($expression instanceof PropertyAccessExpr) {
            $this->collectFreeVariables($expression->object, $params, $free);
            return;
        }

        if ($expression instanceof PropertyAssignExpr) {
            $this->collectFreeVariables($expression->object, $params, $free);
            $this->collectFreeVariables($expression->value, $params, $free);
            return;
        }

        if ($expression instanceof NewExpr) {
            foreach ($expression->arguments as $argument) {
                $this->collectFreeVariables($argument, $params, $free);
            }
            return;
        }

        if ($expression instanceof MatchExpr) {
            $this->collectFreeVariables($expression->subject, $params, $free);

            foreach ($expression->arms as $arm) {
                foreach ($arm->conditions as $condition) {
                    $this->collectFreeVariables($condition, $params, $free);
                }
                $this->collectFreeVariables($arm->body, $params, $free);
            }
        }
    }

    /**
     * @return null|array{params: list<string>, return: string}
     */
    private function builtin(string $name): ?array
    {
        return match ($name) {
            'strlen' => ['params' => ['string'], 'return' => 'int'],
            'intval' => ['params' => ['scalar'], 'return' => 'int'],
            'floatval' => ['params' => ['scalar'], 'return' => 'float'],
            'strval' => ['params' => ['scalar'], 'return' => 'string'],
            'abs' => ['params' => ['numeric'], 'return' => 'same'],
            'count' => ['params' => ['array'], 'return' => 'int'],
            'ui_init' => ['params' => ['string'], 'return' => 'void'],
            'ui_window' => ['params' => ['string', 'int', 'int'], 'return' => 'int'],
            'ui_box' => ['params' => ['bool', 'int'], 'return' => 'int'],
            'ui_label' => ['params' => ['string'], 'return' => 'int'],
            'ui_button' => ['params' => ['string'], 'return' => 'int'],
            'ui_entry' => ['params' => ['string'], 'return' => 'int'],
            'ui_append' => ['params' => ['int', 'int'], 'return' => 'void'],
            'ui_set_child' => ['params' => ['int', 'int'], 'return' => 'void'],
            'ui_present' => ['params' => ['int'], 'return' => 'void'],
            'ui_set_text' => ['params' => ['int', 'string'], 'return' => 'void'],
            'ui_entry_text' => ['params' => ['int'], 'return' => 'string'],
            'ui_set_visible' => ['params' => ['int', 'bool'], 'return' => 'void'],
            'ui_on_click' => ['params' => ['int', 'string'], 'return' => 'void'],
            'ui_on_activate' => ['params' => ['int', 'string'], 'return' => 'void'],
            'ui_after' => ['params' => ['int', 'string'], 'return' => 'void'],
            'ui_click' => ['params' => ['int'], 'return' => 'void'],
            'ui_on_click_closure' => ['params' => ['int', 'callable'], 'return' => 'void'],
            'ui_on_activate_closure' => ['params' => ['int', 'callable'], 'return' => 'void'],
            'ui_after_closure' => ['params' => ['int', 'callable'], 'return' => 'void'],
            'ui_quit' => ['params' => [], 'return' => 'void'],
            'ui_run' => ['params' => [], 'return' => 'int'],
            default => null,
        };
    }
}
