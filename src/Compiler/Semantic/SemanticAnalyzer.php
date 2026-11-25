<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

use Cyonima\Compiler\Ast\AppendExpr;
use Cyonima\Compiler\Ast\ArrayExpr;
use Cyonima\Compiler\Ast\AssignExpr;
use Cyonima\Compiler\Ast\BinaryExpr;
use Cyonima\Compiler\Ast\Block;
use Cyonima\Compiler\Ast\CallExpr;
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
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ast\ReturnStmt;
use Cyonima\Compiler\Ast\Stmt;
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
    /**
     * @param array<string, FunctionInfo> $functions
     */
    public function analyze(Program $program): Analysis
    {
        $functions = $this->collectFunctions($program);

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

        $mainScope = new Scope();
        $this->analyzeStatements($program->statements, $mainScope, 'int', $functions);
        $this->flowStatements($program->statements, []);

        return new Analysis($functions, $mainScope->variables);
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

            $elementType = $this->arrayElementType($iterableType);

            if ($elementType === null) {
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

                if ($existingKey !== null && $existingKey !== 'int') {
                    throw new CompilerException(
                        "ligne {$statement->line} : \${$statement->keyName} est de type {$existingKey}, clé int attendue",
                    );
                }

                $scope->define($statement->keyName, 'int');
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
            $statement->valueType = $valueType;
            $this->analyzeStatement($statement->body, $scope, $returnType, $functions);
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

        if ($expression instanceof VariableExpr) {
            $type = $scope->typeOf($expression->name);

            if ($type === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : variable non définie : \${$expression->name}",
                );
            }

            $expression->type = $type;

            return $type;
        }

        if ($expression instanceof AssignExpr) {
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

            foreach ($expression->elements as $element) {
                $type = $this->analyzeExpr($element, $scope, $functions);

                if ($this->isArrayType($type) || $type === 'void' || $type === 'null') {
                    throw new CompilerException(
                        "ligne {$element->line} : élément de tableau non scalaire : {$type}",
                    );
                }

                $elementType = $this->mergeElementType($elementType, $type, $expression->line);
            }

            $expression->type = $elementType === null ? 'array' : $this->arrayType($elementType);

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
            $this->requireType($indexType, 'int', $expression->line, 'index de tableau');
            $elementType = $this->arrayElementType($arrayType);

            if ($elementType === null) {
                throw new CompilerException(
                    "ligne {$expression->line} : type d’élément inconnu ; initialisez le tableau avec au moins une valeur",
                );
            }

            $expression->type = $elementType;

            return $elementType;
        }

        if ($expression instanceof AppendExpr) {
            $arrayType = $this->analyzeExpr($expression->array, $scope, $functions);

            if (!$this->isArrayType($arrayType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : ajout sur {$arrayType}, tableau attendu",
                );
            }

            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);

            if ($this->isArrayType($valueType) || $valueType === 'void' || $valueType === 'null') {
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
            $arrayType = $this->analyzeExpr($expression->array, $scope, $functions);

            if (!$this->isArrayType($arrayType)) {
                throw new CompilerException(
                    "ligne {$expression->line} : affectation d’index sur {$arrayType}, tableau attendu",
                );
            }

            $indexType = $this->analyzeExpr($expression->index, $scope, $functions);
            $this->requireType($indexType, 'int', $expression->line, 'index de tableau');

            $valueType = $this->analyzeExpr($expression->value, $scope, $functions);

            if ($this->isArrayType($valueType) || $valueType === 'void' || $valueType === 'null') {
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
            foreach ($expression->elements as $element) {
                $assigned = $this->flowExpr($element, $assigned);
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

        throw new CompilerException(
            "ligne {$line} : {$context} attend {$expected}, reçu {$actual}",
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

    private function isArrayType(string $type): bool
    {
        return $type === 'array' || str_starts_with($type, 'array<');
    }

    private function arrayElementType(string $type): ?string
    {
        if (preg_match('/^array<(int|float|bool|string)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function arrayType(string $element): string
    {
        return 'array<' . $element . '>';
    }

    private function isNumeric(string $type): bool
    {
        return $type === 'int' || $type === 'float';
    }

    private function isScalar(string $type): bool
    {
        return in_array($type, ['int', 'float', 'bool', 'string'], true);
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
            'ui_quit' => ['params' => [], 'return' => 'void'],
            'ui_run' => ['params' => [], 'return' => 'int'],
            default => null,
        };
    }
}
