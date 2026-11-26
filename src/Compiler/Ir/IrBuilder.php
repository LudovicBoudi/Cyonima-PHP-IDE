<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

use Cyonima\Compiler\Ast\AppendExpr;
use Cyonima\Compiler\Ast\ArrayExpr;
use Cyonima\Compiler\Ast\AssignExpr;
use Cyonima\Compiler\Ast\BinaryExpr;
use Cyonima\Compiler\Ast\Block;
use Cyonima\Compiler\Ast\BreakStmt;
use Cyonima\Compiler\Ast\CallableCallExpr;
use Cyonima\Compiler\Ast\CallExpr;
use Cyonima\Compiler\Ast\ClosureExpr;
use Cyonima\Compiler\Ast\EchoStmt;
use Cyonima\Compiler\Ast\Expr;
use Cyonima\Compiler\Ast\ExpressionStmt;
use Cyonima\Compiler\Ast\ForStmt;
use Cyonima\Compiler\Ast\ForeachStmt;
use Cyonima\Compiler\Ast\IfStmt;
use Cyonima\Compiler\Ast\IndexAssignExpr;
use Cyonima\Compiler\Ast\IndexExpr;
use Cyonima\Compiler\Ast\Literal;
use Cyonima\Compiler\Ast\MatchExpr;
use Cyonima\Compiler\Ast\MethodCallExpr;
use Cyonima\Compiler\Ast\NewExpr;
use Cyonima\Compiler\Ast\ParentCallExpr;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ast\PropertyAccessExpr;
use Cyonima\Compiler\Ast\PropertyAssignExpr;
use Cyonima\Compiler\Ast\StaticCallExpr;
use Cyonima\Compiler\Ast\StaticPropertyAssignExpr;
use Cyonima\Compiler\Ast\StaticPropertyExpr;
use Cyonima\Compiler\Ast\ReturnStmt;
use Cyonima\Compiler\Ast\Stmt;
use Cyonima\Compiler\Ast\SwitchStmt;
use Cyonima\Compiler\Ast\ThrowStmt;
use Cyonima\Compiler\Ast\TryStmt;
use Cyonima\Compiler\Ast\UnaryExpr;
use Cyonima\Compiler\Ast\VariableExpr;
use Cyonima\Compiler\Ast\WhileStmt;
use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Semantic\Analysis;

/**
 * Abaissement de l’AST vers une IR linéaire à trois adresses.
 *
 * L’IR reste indépendante du backend C : elle peut recevoir d’autres cibles
 * (LLVM, interpréteur, bytecode) sans modifier le frontend.
 */
final class IrBuilder
{
    private int $temporary = 0;
    private int $label = 0;

    /** @var list<string> */
    private array $breakLabels = [];

    /** @var list<string> */
    private array $exceptionLabels = [];

    /** @var list<IrClosure> */
    private array $closures = [];

    private int $closureCounter = 0;

    private ?string $currentClass = null;

    /** @var array<string, true> */
    private array $currentByRefCaptures = [];

    /** @var list<Block> */
    private array $finallyReturnStack = [];

    /** @var list<Block> */
    private array $finallyThrowStack = [];

    public function __construct(private readonly Analysis $analysis)
    {
    }

    public function build(Program $program): IrProgram
    {
        $functions = [];

        foreach ($program->functions as $function) {
            $info = $this->analysis->functions[$function->name]
                ?? throw new CompilerException("fonction absente de l’analyse : {$function->name}");

            $params = [];
            $paramNames = [];

            foreach ($function->params as $param) {
                $params[] = new IrParam($param->name, $param->type);
                $paramNames[$param->name] = true;
            }

            $variables = array_diff_key($info->variables, $paramNames);
            $ir = new IrFunction(
                $function->name,
                $params,
                $function->returnType,
                $variables,
            );
            $exceptionLabel = $this->newLabel();
            $normalEnd = $this->newLabel();
            $this->breakLabels = [];
            $this->exceptionLabels = [$exceptionLabel];
            $this->lowerStatements($function->body->statements, $ir);
            $this->exceptionLabels = [];
            $ir->instructions[] = new JumpInstruction($normalEnd);
            $ir->instructions[] = new LabelInstruction($exceptionLabel);
            $ir->instructions[] = new ReturnInstruction(
                $this->defaultOperand($function->returnType),
            );
            $ir->instructions[] = new LabelInstruction($normalEnd);
            $ir->instructions[] = new ReturnInstruction(
                $this->defaultOperand($function->returnType),
            );
            $functions[] = $ir;
        }

        $methods = [];

        foreach ($program->classes as $classDeclaration) {
            $classInfo = $this->analysis->classes[$classDeclaration->name]
                ?? throw new CompilerException(
                    "classe absente de l’analyse : {$classDeclaration->name}",
                );

            foreach ($classDeclaration->methods as $method) {
                if ($method->isAbstract || $method->body === null) {
                    continue;
                }

                $info = $classInfo->methods[$method->name]
                    ?? throw new CompilerException(
                        "méthode absente de l’analyse : {$classDeclaration->name}::{$method->name}",
                    );
                $params = $method->isStatic
                    ? []
                    : [new IrParam('this', $classDeclaration->name)];
                $paramNames = $method->isStatic ? [] : ['this' => true];

                foreach ($method->params as $param) {
                    $params[] = new IrParam($param->name, $param->type);
                    $paramNames[$param->name] = true;
                }

                $variables = array_diff_key($info->variables, $paramNames);
                $ir = new IrFunction(
                    $classDeclaration->name . '__' . $method->name,
                    $params,
                    $method->returnType,
                    $variables,
                );
                $exceptionLabel = $this->newLabel();
                $normalEnd = $this->newLabel();
                $this->breakLabels = [];
                $this->exceptionLabels = [$exceptionLabel];
                $this->currentClass = $classDeclaration->name;
                $this->lowerStatements($method->body->statements, $ir);
                $this->currentClass = null;
                $this->exceptionLabels = [];
                $ir->instructions[] = new JumpInstruction($normalEnd);
                $ir->instructions[] = new LabelInstruction($exceptionLabel);
                $ir->instructions[] = new ReturnInstruction(
                    $this->defaultOperand($method->returnType),
                );
                $ir->instructions[] = new LabelInstruction($normalEnd);
                $ir->instructions[] = new ReturnInstruction(
                    $this->defaultOperand($method->returnType),
                );
                $methods[] = new IrMethod(
                    $classDeclaration->name,
                    $method->name,
                    $ir,
                );
            }
        }

        $main = new IrFunction('__main', [], 'int', $this->analysis->mainVariables);
        $exceptionLabel = $this->newLabel();
        $normalEnd = $this->newLabel();
        $this->breakLabels = [];
        $this->exceptionLabels = [$exceptionLabel];
        $this->lowerStatements($program->statements, $main);
        $this->exceptionLabels = [];
        $main->instructions[] = new JumpInstruction($normalEnd);
        $main->instructions[] = new LabelInstruction($exceptionLabel);
        $main->instructions[] = new UncaughtExceptionInstruction();
        $main->instructions[] = new LabelInstruction($normalEnd);

        return new IrProgram($functions, $methods, $this->closures, $main);
    }

    /** @param list<Stmt> $statements */
    private function lowerStatements(array $statements, IrFunction $function): void
    {
        foreach ($statements as $statement) {
            $this->lowerStatement($statement, $function);
        }
    }

    private function lowerStatement(Stmt $statement, IrFunction $function): void
    {
        if ($statement instanceof Block) {
            $this->lowerStatements($statement->statements, $function);
            return;
        }

        if ($statement instanceof EchoStmt) {
            foreach ($statement->expressions as $expression) {
                $function->instructions[] = new EchoInstruction(
                    $this->lowerExpr($expression, $function),
                );
            }
            return;
        }

        if ($statement instanceof ReturnStmt) {
            if ($this->finallyReturnStack !== []) {
                $this->lowerFinallyBlocks($this->finallyReturnStack, $function);
            }

            $function->instructions[] = new ReturnInstruction(
                $statement->value === null ? null : $this->lowerExpr($statement->value, $function),
            );
            return;
        }

        if ($statement instanceof IfStmt) {
            $condition = $this->lowerExpr($statement->condition, $function);

            if ($statement->else === null) {
                $end = $this->newLabel();
                $function->instructions[] = new JumpIfFalseInstruction($condition, $end);
                $this->lowerStatement($statement->then, $function);
                $function->instructions[] = new LabelInstruction($end);
                return;
            }

            $elseLabel = $this->newLabel();
            $endLabel = $this->newLabel();
            $function->instructions[] = new JumpIfFalseInstruction($condition, $elseLabel);
            $this->lowerStatement($statement->then, $function);
            $function->instructions[] = new JumpInstruction($endLabel);
            $function->instructions[] = new LabelInstruction($elseLabel);
            $this->lowerStatement($statement->else, $function);
            $function->instructions[] = new LabelInstruction($endLabel);
            return;
        }

        if ($statement instanceof WhileStmt) {
            $start = $this->newLabel();
            $end = $this->newLabel();
            $function->instructions[] = new LabelInstruction($start);
            $condition = $this->lowerExpr($statement->condition, $function);
            $function->instructions[] = new JumpIfFalseInstruction($condition, $end);
            $this->lowerStatement($statement->body, $function);
            $function->instructions[] = new GcInstruction();
            $function->instructions[] = new JumpInstruction($start);
            $function->instructions[] = new LabelInstruction($end);
            return;
        }

        if ($statement instanceof ForStmt) {
            if ($statement->init !== null) {
                $this->lowerExpr($statement->init, $function);
            }

            $start = $this->newLabel();
            $end = $this->newLabel();
            $function->instructions[] = new LabelInstruction($start);

            if ($statement->condition !== null) {
                $condition = $this->lowerExpr($statement->condition, $function);
                $function->instructions[] = new JumpIfFalseInstruction($condition, $end);
            }

            $this->lowerStatement($statement->body, $function);

            if ($statement->step !== null) {
                $this->lowerExpr($statement->step, $function);
            }

            $function->instructions[] = new GcInstruction();
            $function->instructions[] = new JumpInstruction($start);
            $function->instructions[] = new LabelInstruction($end);
            return;
        }

        if ($statement instanceof ForeachStmt) {
            $array = $this->lowerExpr($statement->iterable, $function);
            $length = $this->newTemporary('int', $function);
            $function->instructions[] = new CallInstruction('count', [$array], $length);
            $index = $this->newTemporary('int', $function);
            $function->instructions[] = new AssignInstruction(
                $index,
                new ConstantOperand('int', 0),
            );

            $start = $this->newLabel();
            $end = $this->newLabel();
            $function->instructions[] = new LabelInstruction($start);
            $condition = $this->newTemporary('bool', $function);
            $function->instructions[] = new BinaryInstruction('<', $index, $length, $condition);
            $function->instructions[] = new JumpIfFalseInstruction($condition, $end);

            $iterableType = (string) $statement->iterable->type;

            if ($this->isMapType($iterableType)) {
                $mapValueType = $this->mapValueType($iterableType)
                    ?? throw new CompilerException(
                        "ligne {$statement->line} : type de valeur foreach inconnu",
                    );
                $key = $this->newTemporary('string', $function);
                $function->instructions[] = new MapKeyInstruction($array, $index, $key);
                $value = $this->newTemporary($mapValueType, $function);
                $function->instructions[] = new MapValueInstruction(
                    $array,
                    $index,
                    $value,
                    $mapValueType,
                );

                if ($statement->keyName !== null) {
                    $function->instructions[] = new AssignInstruction(
                        new VariableOperand(
                            $statement->keyName,
                            $statement->keyType ?? 'string',
                        ),
                        $key,
                    );
                }

                $function->instructions[] = new AssignInstruction(
                    new VariableOperand(
                        $statement->valueName,
                        $statement->valueType ?? $mapValueType,
                    ),
                    $value,
                );
            } else {
                $arrayElementType = $this->arrayElementType($iterableType)
                    ?? throw new CompilerException(
                        "ligne {$statement->line} : type d’élément foreach inconnu",
                    );
                $value = $this->newTemporary($arrayElementType, $function);
                $function->instructions[] = new IndexInstruction(
                    $array,
                    $index,
                    $value,
                    $arrayElementType,
                );

                if ($statement->keyName !== null) {
                    $function->instructions[] = new AssignInstruction(
                        new VariableOperand(
                            $statement->keyName,
                            $statement->keyType ?? 'int',
                        ),
                        $index,
                    );
                }

                $function->instructions[] = new AssignInstruction(
                    new VariableOperand(
                        $statement->valueName,
                        $statement->valueType ?? $arrayElementType,
                    ),
                    $value,
                );
            }

            $this->lowerStatement($statement->body, $function);
            $function->instructions[] = new BinaryInstruction(
                '+',
                $index,
                new ConstantOperand('int', 1),
                $index,
            );
            $function->instructions[] = new GcInstruction();
            $function->instructions[] = new JumpInstruction($start);
            $function->instructions[] = new LabelInstruction($end);
            return;
        }

        if ($statement instanceof ExpressionStmt) {
            $this->lowerExpr($statement->expression, $function);
            return;
        }

        if ($statement instanceof SwitchStmt) {
            $subject = $this->lowerExpr($statement->subject, $function);
            $end = $this->newLabel();
            $labels = [];

            foreach ($statement->cases as $index => $case) {
                $labels[$index] = $this->newLabel();
            }

            $defaultLabel = null;

            foreach ($statement->cases as $index => $case) {
                if ($case->condition === null) {
                    $defaultLabel = $labels[$index];
                    continue;
                }

                $condition = $this->lowerExpr($case->condition, $function);
                $match = $this->newTemporary('bool', $function);
                $function->instructions[] = new BinaryInstruction(
                    '==',
                    $subject,
                    $condition,
                    $match,
                );
                $function->instructions[] = new JumpIfTrueInstruction(
                    $match,
                    $labels[$index],
                );
            }

            $function->instructions[] = new JumpInstruction($defaultLabel ?? $end);
            $this->breakLabels[] = $end;

            foreach ($statement->cases as $index => $case) {
                $function->instructions[] = new LabelInstruction($labels[$index]);
                $this->lowerStatements($case->statements, $function);
            }

            array_pop($this->breakLabels);
            $function->instructions[] = new LabelInstruction($end);
            return;
        }

        if ($statement instanceof BreakStmt) {
            if ($this->breakLabels === []) {
                throw new CompilerException(
                    "ligne {$statement->line} : break hors switch",
                );
            }

            if ($this->finallyReturnStack !== []) {
                throw new CompilerException(
                    "ligne {$statement->line} : break dans try/finally non supporté",
                );
            }

            $function->instructions[] = new JumpInstruction(
                $this->breakLabels[count($this->breakLabels) - 1],
            );
            return;
        }

        if ($statement instanceof ThrowStmt) {
            $value = $this->lowerExpr($statement->value, $function);

            if ($this->finallyThrowStack !== []) {
                $this->lowerFinallyBlocks($this->finallyThrowStack, $function);
            }

            if ($statement->throwType === null || $statement->throwType === 'string') {
                $function->instructions[] = new ThrowInstruction($value);
            } else {
                $message = $this->newTemporary('string', $function);
                $function->instructions[] = new PropertyGetInstruction(
                    $value,
                    'message',
                    $message,
                );
                $function->instructions[] = new ThrowObjectInstruction($value, $message);
            }

            $handler = $this->exceptionLabels[count($this->exceptionLabels) - 1]
                ?? throw new CompilerException(
                    "ligne {$statement->line} : aucun gestionnaire d’exception",
                );
            $function->instructions[] = new JumpInstruction($handler);
            return;
        }

        if ($statement instanceof TryStmt) {
            $this->lowerTry($statement, $function);
            return;
        }

        throw new CompilerException(
            "ligne {$statement->line} : instruction non supportée par l’IR",
        );
    }

    /** @param list<Block> $blocks */
    private function lowerFinallyBlocks(array $blocks, IrFunction $function): void
    {
        $savedReturn = $this->finallyReturnStack;
        $savedThrow = $this->finallyThrowStack;
        $this->finallyReturnStack = [];
        $this->finallyThrowStack = [];

        foreach (array_reverse($blocks) as $block) {
            $this->lowerStatements($block->statements, $function);
        }

        $this->finallyReturnStack = $savedReturn;
        $this->finallyThrowStack = $savedThrow;
    }

    private function lowerTry(TryStmt $statement, IrFunction $function): void
    {
        $outer = $this->exceptionLabels[count($this->exceptionLabels) - 1]
            ?? throw new CompilerException(
                "ligne {$statement->line} : aucun gestionnaire d’exception englobant",
            );
        $hasCatches = $statement->catches !== [];
        $hasFinally = $statement->finally !== null;
        $endLabel = $this->newLabel();
        $finallyLabel = $hasFinally ? $this->newLabel() : $endLabel;
        $catchLabel = $hasCatches ? $this->newLabel() : null;
        $exceptionFinallyLabel = ($hasFinally && !$hasCatches)
            ? $this->newLabel()
            : null;
        $handler = $hasCatches ? $catchLabel : ($exceptionFinallyLabel ?? $outer);

        $this->exceptionLabels[] = $handler;

        if ($hasFinally) {
            $this->finallyReturnStack[] = $statement->finally;
        }

        $this->lowerStatement($statement->body, $function);

        if ($hasFinally) {
            array_pop($this->finallyReturnStack);
        }

        array_pop($this->exceptionLabels);
        $function->instructions[] = new JumpInstruction($finallyLabel);

        if ($hasCatches) {
            $function->instructions[] = new LabelInstruction((string) $catchLabel);
            $bodyLabels = [];

            foreach ($statement->catches as $index => $_catch) {
                $bodyLabels[$index] = $this->newLabel();
            }

            $noMatchLabel = $this->newLabel();

            foreach ($statement->catches as $index => $catch) {
                $match = $this->newTemporary('bool', $function);
                $function->instructions[] = new ExceptionMatchInstruction(
                    $catch->type === 'string' ? null : $catch->type,
                    $match,
                );
                $function->instructions[] = new JumpIfTrueInstruction(
                    $match,
                    $bodyLabels[$index],
                );
            }

            $function->instructions[] = new JumpInstruction($noMatchLabel);
            $commonType = $statement->catches[0]->type === 'string'
                ? 'string'
                : 'Exception';

            foreach ($statement->catches as $index => $catch) {
                $function->instructions[] = new LabelInstruction($bodyLabels[$index]);
                $captureType = $catch->type === 'string' ? 'string' : $catch->type;
                $capture = $this->newTemporary($captureType, $function);

                if ($catch->type === 'string') {
                    $function->instructions[] = new CaptureExceptionInstruction($capture);
                } else {
                    $function->instructions[] = new CaptureExceptionObjectInstruction(
                        $capture,
                    );
                }

                $function->instructions[] = new AssignInstruction(
                    new VariableOperand($catch->variable, $commonType),
                    $capture,
                );

                if ($hasFinally) {
                    $this->finallyReturnStack[] = $statement->finally;
                    $this->finallyThrowStack[] = $statement->finally;
                }

                $this->lowerStatement($catch->body, $function);

                if ($hasFinally) {
                    array_pop($this->finallyReturnStack);
                    array_pop($this->finallyThrowStack);
                }

                $function->instructions[] = new JumpInstruction($finallyLabel);
            }

            $function->instructions[] = new LabelInstruction($noMatchLabel);

            if ($hasFinally) {
                $function->instructions[] = new SuspendExceptionInstruction();
                $this->lowerStatement($statement->finally, $function);
                $this->appendExceptionCheck($function);
                $function->instructions[] = new ResumeExceptionInstruction();
            }

            $function->instructions[] = new JumpInstruction($outer);
        } elseif ($exceptionFinallyLabel !== null) {
            $function->instructions[] = new LabelInstruction($exceptionFinallyLabel);
            $function->instructions[] = new SuspendExceptionInstruction();
            $this->lowerStatement($statement->finally, $function);
            $this->appendExceptionCheck($function);
            $function->instructions[] = new ResumeExceptionInstruction();
            $function->instructions[] = new JumpInstruction($outer);
        }

        if ($hasFinally) {
            $function->instructions[] = new LabelInstruction($finallyLabel);
            $this->lowerStatement($statement->finally, $function);
            $this->appendExceptionCheck($function);
        }

        $function->instructions[] = new LabelInstruction($endLabel);
    }

    private function lowerExpr(Expr $expression, IrFunction $function): Operand
    {
        if ($expression instanceof Literal) {
            return new ConstantOperand($expression->literalType, $expression->value);
        }

        if ($expression instanceof ClosureExpr) {
            $id = $this->closureCounter++;
            $signature = $this->callableSignature((string) $expression->type);
            $params = [];
            $paramNames = [];

            foreach ($expression->params as $param) {
                $params[] = new IrParam($param->name, $param->type);
                $paramNames[$param->name] = true;
            }

            $captureNames = [];

            foreach ($expression->captureTypes as $name => $_type) {
                $captureNames[$name] = true;
            }

            $variables = array_diff_key(
                $expression->variables,
                $paramNames,
                $captureNames,
            );
            $ir = new IrFunction(
                'closure_' . $id,
                $params,
                $signature['return'],
                $variables,
            );
            $savedBreakLabels = $this->breakLabels;
            $savedExceptionLabels = $this->exceptionLabels;
            $savedByRefCaptures = $this->currentByRefCaptures;
            $exceptionLabel = $this->newLabel();
            $normalEnd = $this->newLabel();
            $this->breakLabels = [];
            $this->exceptionLabels = [$exceptionLabel];
            $this->currentByRefCaptures = $expression->captureByRef;

            if ($expression->arrow) {
                if ($expression->expression === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : corps de arrow function manquant",
                    );
                }

                $body = $this->lowerExpr($expression->expression, $ir);
                $ir->instructions[] = new ReturnInstruction(
                    $body->type === 'void' ? null : $body,
                );
            } elseif ($expression->body !== null) {
                $this->lowerStatements($expression->body->statements, $ir);
            } else {
                throw new CompilerException(
                    "ligne {$expression->line} : corps de closure manquant",
                );
            }

            $this->exceptionLabels = [];
            $ir->instructions[] = new JumpInstruction($normalEnd);
            $ir->instructions[] = new LabelInstruction($exceptionLabel);
            $ir->instructions[] = new ReturnInstruction(
                $this->defaultOperand($signature['return']),
            );
            $ir->instructions[] = new LabelInstruction($normalEnd);
            $ir->instructions[] = new ReturnInstruction(
                $this->defaultOperand($signature['return']),
            );
            $this->breakLabels = $savedBreakLabels;
            $this->exceptionLabels = $savedExceptionLabels;
            $this->currentByRefCaptures = $savedByRefCaptures;
            $closure = new IrClosure(
                $id,
                $params,
                $expression->captureTypes,
                $expression->captureByRef,
                $signature['return'],
                $ir,
            );
            $this->closures[] = $closure;
            $captures = [];

            foreach ($expression->captureTypes as $name => $type) {
                if (isset($expression->captureByRef[$name])) {
                    if (!isset($this->currentByRefCaptures[$name])) {
                        $function->boxedVariables[$name] = true;
                    }

                    $captures[] = new CellOperand($name, $type);
                    continue;
                }

                $variable = new VariableExpr($name, $expression->line);
                $variable->type = $type;
                $captures[] = $this->lowerExpr($variable, $function);
            }

            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new ClosureCreateInstruction(
                $closure,
                $captures,
                $result,
            );

            return $result;
        }

        if ($expression instanceof CallableCallExpr) {
            $closure = $this->lowerExpr($expression->callee, $function);
            $signature = $this->callableSignature(
                $this->unwrapNullableType((string) $expression->callee->type),
            );
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );

            if ($signature['return'] === 'void') {
                $function->instructions[] = new ClosureCallInstruction(
                    $closure,
                    $arguments,
                    $signature['params'],
                    'void',
                    null,
                );
                $this->appendExceptionCheck($function);

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary($signature['return'], $function);
            $function->instructions[] = new ClosureCallInstruction(
                $closure,
                $arguments,
                $signature['params'],
                $signature['return'],
                $result,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }

        if ($expression instanceof ParentCallExpr) {
            $target = $expression->targetClass
                ?? throw new CompilerException(
                    "ligne {$expression->line} : cible parent:: inconnue",
                );
            $methodInfo = $this->analysis->classes[$target]->methods[$expression->method]
                ?? throw new CompilerException(
                    "méthode parente absente : {$target}::{$expression->method}",
                );
            $object = new VariableOperand('this', $this->currentClass ?? $target);
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );
            $parameterTypes = array_values($methodInfo->params);

            if ($methodInfo->returnType === 'void') {
                $function->instructions[] = new ParentCallInstruction(
                    $target,
                    $expression->method,
                    $object,
                    $arguments,
                    $parameterTypes,
                    'void',
                    null,
                );
                $this->appendExceptionCheck($function);

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary($methodInfo->returnType, $function);
            $function->instructions[] = new ParentCallInstruction(
                $target,
                $expression->method,
                $object,
                $arguments,
                $parameterTypes,
                $methodInfo->returnType,
                $result,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }

        if ($expression instanceof NewExpr) {
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );
            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new NewInstruction(
                $expression->className,
                $arguments,
                $result,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }

        if ($expression instanceof PropertyAccessExpr) {
            $nullable = $this->isNullableType((string) $expression->object->type);
            $object = $this->lowerExpr($expression->object, $function);
            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new PropertyGetInstruction(
                $object,
                $expression->property,
                $result,
            );

            if ($nullable) {
                $this->appendExceptionCheck($function);
            }

            return $result;
        }

        if ($expression instanceof PropertyAssignExpr) {
            $nullable = $this->isNullableType((string) $expression->object->type);
            $object = $this->lowerExpr($expression->object, $function);
            $value = $this->lowerExpr($expression->value, $function);
            $function->instructions[] = new PropertySetInstruction(
                $object,
                $expression->property,
                $value,
            );

            if ($nullable) {
                $this->appendExceptionCheck($function);
            }

            return $value;
        }

        if ($expression instanceof StaticPropertyExpr) {
            $owner = $expression->ownerClass
                ?? throw new CompilerException(
                    "ligne {$expression->line} : propriétaire statique inconnu",
                );
            $class = $this->analysis->classes[$owner]
                ?? throw new CompilerException(
                    "classe absente de l’analyse : {$owner}",
                );
            $propertyType = $class->staticProperties[$expression->property]
                ?? throw new CompilerException(
                    "propriété statique absente : {$owner}::\${$expression->property}",
                );
            $result = $this->newTemporary($propertyType, $function);
            $function->instructions[] = new StaticPropertyGetInstruction(
                $owner,
                $expression->property,
                $propertyType,
                $result,
            );

            return $result;
        }

        if ($expression instanceof StaticPropertyAssignExpr) {
            $owner = $expression->ownerClass
                ?? throw new CompilerException(
                    "ligne {$expression->line} : propriétaire statique inconnu",
                );
            $class = $this->analysis->classes[$owner]
                ?? throw new CompilerException(
                    "classe absente de l’analyse : {$owner}",
                );
            $propertyType = $class->staticProperties[$expression->property]
                ?? throw new CompilerException(
                    "propriété statique absente : {$owner}::\${$expression->property}",
                );
            $value = $this->lowerExpr($expression->value, $function);
            $function->instructions[] = new StaticPropertySetInstruction(
                $owner,
                $expression->property,
                $propertyType,
                $value,
            );

            return $value;
        }

        if ($expression instanceof StaticCallExpr) {
            $owner = $expression->resolvedClass
                ?? throw new CompilerException(
                    "ligne {$expression->line} : cible statique inconnue",
                );
            $methodInfo = $this->analysis->classes[$owner]->methods[$expression->method]
                ?? throw new CompilerException(
                    "méthode statique absente : {$owner}::{$expression->method}",
                );
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );
            $parameterTypes = array_values($methodInfo->params);

            if ($methodInfo->returnType === 'void') {
                $function->instructions[] = new StaticCallInstruction(
                    $owner,
                    $expression->method,
                    $arguments,
                    $parameterTypes,
                    'void',
                    null,
                );
                $this->appendExceptionCheck($function);

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary($methodInfo->returnType, $function);
            $function->instructions[] = new StaticCallInstruction(
                $owner,
                $expression->method,
                $arguments,
                $parameterTypes,
                $methodInfo->returnType,
                $result,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }

        if ($expression instanceof MethodCallExpr) {
            $object = $this->lowerExpr($expression->object, $function);
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );
            $className = $this->unwrapNullableType((string) $expression->object->type);

            if ($expression->type === 'void') {
                $function->instructions[] = new MethodCallInstruction(
                    $className,
                    $expression->method,
                    $object,
                    $arguments,
                    null,
                );
                $this->appendExceptionCheck($function);

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new MethodCallInstruction(
                $className,
                $expression->method,
                $object,
                $arguments,
                $result,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }
        if ($expression instanceof MatchExpr) {
            $subject = $this->lowerExpr($expression->subject, $function);
            $result = $this->newTemporary((string) $expression->type, $function);
            $end = $this->newLabel();
            $labels = [];

            foreach ($expression->arms as $index => $arm) {
                $labels[$index] = $this->newLabel();
            }

            $defaultLabel = null;

            foreach ($expression->arms as $index => $arm) {
                if ($arm->conditions === []) {
                    $defaultLabel = $labels[$index];
                    continue;
                }

                foreach ($arm->conditions as $condition) {
                    $conditionValue = $this->lowerExpr($condition, $function);
                    $match = $this->newTemporary('bool', $function);
                    $function->instructions[] = new BinaryInstruction(
                        '===',
                        $subject,
                        $conditionValue,
                        $match,
                    );
                    $function->instructions[] = new JumpIfTrueInstruction(
                        $match,
                        $labels[$index],
                    );
                }
            }

            $function->instructions[] = new JumpInstruction($defaultLabel ?? $end);

            foreach ($expression->arms as $index => $arm) {
                $function->instructions[] = new LabelInstruction($labels[$index]);
                $body = $this->lowerExpr($arm->body, $function);
                $function->instructions[] = new AssignInstruction($result, $body);
                $function->instructions[] = new JumpInstruction($end);
            }

            $function->instructions[] = new LabelInstruction($end);

            return $result;
        }

        if ($expression instanceof VariableExpr) {
            return new VariableOperand($expression->name, (string) $expression->type);
        }

        if ($expression instanceof AssignExpr) {
            $declared = $this->variableType($function, $expression->name);

            if ($declared !== null
                && $declared !== 'array'
                && $expression->value instanceof ArrayExpr
                && $expression->value->type === 'array') {
                $expression->value->type = $declared;
            }

            $value = $this->lowerExpr($expression->value, $function);
            $target = new VariableOperand(
                $expression->name,
                $declared ?? $expression->type ?? $value->type,
            );
            $function->instructions[] = new AssignInstruction($target, $value);

            return $target;
        }

        if ($expression instanceof ArrayExpr) {
            $type = (string) $expression->type;
            $result = $this->newTemporary($type, $function);

            if ($this->isMapType($type)) {
                $valueType = $this->mapValueType($type);

                if ($valueType === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : type de valeur associative inconnu",
                    );
                }

                $entries = [];

                foreach ($expression->elements as $item) {
                    if ($item->key === null) {
                        throw new CompilerException(
                            "ligne {$item->line} : clé associative manquante",
                        );
                    }

                    $entries[] = [
                        $this->lowerExpr($item->key, $function),
                        $this->lowerExpr($item->value, $function),
                    ];
                }

                $function->instructions[] = new MapInstruction($valueType, $entries, $result);

                return $result;
            }

            $elementType = $this->arrayElementType($type);
            $elements = [];

            foreach ($expression->elements as $item) {
                $elements[] = $this->lowerExpr($item->value, $function);
            }

            $function->instructions[] = new ArrayInstruction($elementType, $elements, $result);

            return $result;
        }

        if ($expression instanceof IndexExpr) {
            $array = $this->lowerExpr($expression->array, $function);
            $index = $expression->index === null
                ? throw new CompilerException(
                    "ligne {$expression->line} : index manquant en lecture",
                )
                : $this->lowerExpr($expression->index, $function);
            $result = $this->newTemporary((string) $expression->type, $function);
            $arrayType = (string) $expression->array->type;

            if ($this->isMapType($arrayType)) {
                $function->instructions[] = new MapIndexInstruction(
                    $array,
                    $index,
                    $result,
                    (string) $expression->type,
                );
            } else {
                $function->instructions[] = new IndexInstruction(
                    $array,
                    $index,
                    $result,
                    (string) $expression->type,
                );
            }

            return $result;
        }

        if ($expression instanceof AppendExpr) {
            $array = $this->lowerExpr($expression->array, $function);
            $value = $this->lowerExpr($expression->value, $function);
            $function->instructions[] = new AppendInstruction(
                $array,
                $value,
                (string) $expression->type,
            );

            return $value;
        }

        if ($expression instanceof IndexAssignExpr) {
            $array = $this->lowerExpr($expression->array, $function);
            $index = $this->lowerExpr($expression->index, $function);
            $value = $this->lowerExpr($expression->value, $function);
            $arrayType = (string) $expression->array->type;

            if ($this->isMapType($arrayType)) {
                $function->instructions[] = new SetMapIndexInstruction(
                    $array,
                    $index,
                    $value,
                    (string) $expression->type,
                );
            } else {
                $function->instructions[] = new SetIndexInstruction(
                    $array,
                    $index,
                    $value,
                    (string) $expression->type,
                );
            }

            return $value;
        }

        if ($expression instanceof BinaryExpr) {
            $left = $this->lowerExpr($expression->left, $function);
            $right = $this->lowerExpr($expression->right, $function);
            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new BinaryInstruction(
                $expression->operator,
                $left,
                $right,
                $result,
            );

            return $result;
        }

        if ($expression instanceof UnaryExpr) {
            $operand = $this->lowerExpr($expression->operand, $function);
            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new UnaryInstruction(
                $expression->operator,
                $operand,
                $result,
            );

            return $result;
        }

        if ($expression instanceof CallExpr) {
            if ($expression->name === 'isset') {
                $argument = $expression->arguments[0] ?? null;

                if (!$argument instanceof IndexExpr || $argument->index === null) {
                    throw new CompilerException(
                        "ligne {$expression->line} : isset attend \$tableau[\$clé]",
                    );
                }

                $collection = $this->lowerExpr($argument->array, $function);
                $key = $this->lowerExpr($argument->index, $function);
                $result = $this->newTemporary('bool', $function);
                $function->instructions[] = new HasIndexInstruction(
                    $collection,
                    $key,
                    $result,
                    $this->isMapType((string) $argument->array->type),
                );

                return $result;
            }

            $callName = $expression->resolvedName ?? $expression->name;
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );
            $parameterTypes = isset($this->analysis->functions[$callName])
                ? array_values($this->analysis->functions[$callName]->params)
                : null;

            if ($expression->type === 'void') {
                $function->instructions[] = new CallInstruction(
                    $callName,
                    $arguments,
                    null,
                    $parameterTypes,
                );
                $this->appendExceptionCheck($function);

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new CallInstruction(
                $callName,
                $arguments,
                $result,
                $parameterTypes,
            );
            $this->appendExceptionCheck($function);

            return $result;
        }

        throw new CompilerException(
            "ligne {$expression->line} : expression non supportée par l’IR",
        );
    }

    private function newTemporary(string $type, IrFunction $function): TemporaryOperand
    {
        $id = $this->temporary++;
        $temporary = new TemporaryOperand($id, $type);
        $function->temporaries[$id] = $type;

        return $temporary;
    }

    private function appendExceptionCheck(IrFunction $function): void
    {
        $handler = $this->exceptionLabels[count($this->exceptionLabels) - 1] ?? null;

        if ($handler !== null) {
            $function->instructions[] = new ExceptionCheckInstruction($handler);
        }
    }

    private function variableType(IrFunction $function, string $name): ?string
    {
        foreach ($function->params as $param) {
            if ($param->name === $name) {
                return $param->type;
            }
        }

        return $function->variables[$name] ?? null;
    }

    private function defaultOperand(string $type): ?Operand
    {
        if ($this->isNullableType($type)) {
            return new ConstantOperand($type, null);
        }

        if (isset($this->analysis->classes[$type]) || $this->isCallableType($type)) {
            return new ConstantOperand($type, null);
        }

        if ($this->isListType($type) || $this->isMapType($type)) {
            return new ConstantOperand($type, null);
        }

        return match ($type) {
            'void' => null,
            'int' => new ConstantOperand('int', 0),
            'float' => new ConstantOperand('float', 0.0),
            'bool' => new ConstantOperand('bool', false),
            'string' => new ConstantOperand('string', ''),
            default => throw new CompilerException(
                'type de retour non supporté pour le chemin d’exception : ' . $type,
            ),
        };
    }

    private function isCallableType(string $type): bool
    {
        return $type === 'callable' || preg_match(
            '/^callable\(([^)]*)\):(int|float|bool|string|void)$/',
            $type,
        ) === 1;
    }

    private function isNullableType(string $type): bool
    {
        return str_starts_with($type, '?');
    }

    private function unwrapNullableType(string $type): string
    {
        return $this->isNullableType($type) ? substr($type, 1) : $type;
    }

    private function arrayElementType(string $type): ?string
    {
        if (preg_match('/^array<([^,<>]+)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function isListType(string $type): bool
    {
        return $type === 'array'
            || preg_match('/^array<([^,<>]+)>$/', $type) === 1;
    }

    private function isMapType(string $type): bool
    {
        return preg_match('/^array<string,([^,<>]+)>$/', $type) === 1;
    }

    private function mapValueType(string $type): ?string
    {
        if (preg_match('/^array<string,([^,<>]+)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
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

        return [
            'params' => $matches[1] === '' ? [] : explode(',', $matches[1]),
            'return' => $matches[2],
        ];
    }

    private function newLabel(): string
    {
        return 'L' . $this->label++;
    }
}
