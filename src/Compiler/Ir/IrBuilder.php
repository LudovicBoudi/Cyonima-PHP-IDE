<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

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
            $this->lowerStatements($function->body->statements, $ir);
            $functions[] = $ir;
        }

        $main = new IrFunction('__main', [], 'int', $this->analysis->mainVariables);
        $this->lowerStatements($program->statements, $main);

        return new IrProgram($functions, $main);
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

            $arrayElementType = $this->arrayElementType((string) $statement->iterable->type)
                ?? throw new CompilerException(
                    "ligne {$statement->line} : type d’élément foreach inconnu",
                );
            $element = $this->newTemporary($arrayElementType, $function);
            $function->instructions[] = new IndexInstruction(
                $array,
                $index,
                $element,
                $arrayElementType,
            );

            if ($statement->keyName !== null) {
                $function->instructions[] = new AssignInstruction(
                    new VariableOperand($statement->keyName, 'int'),
                    $index,
                );
            }

            $function->instructions[] = new AssignInstruction(
                new VariableOperand(
                    $statement->valueName,
                    $statement->valueType ?? $arrayElementType,
                ),
                $element,
            );
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

        throw new CompilerException(
            "ligne {$statement->line} : instruction non supportée par l’IR",
        );
    }

    private function lowerExpr(Expr $expression, IrFunction $function): Operand
    {
        if ($expression instanceof Literal) {
            return new ConstantOperand($expression->literalType, $expression->value);
        }

        if ($expression instanceof VariableExpr) {
            return new VariableOperand($expression->name, (string) $expression->type);
        }

        if ($expression instanceof AssignExpr) {
            $value = $this->lowerExpr($expression->value, $function);
            $target = new VariableOperand(
                $expression->name,
                (string) ($expression->type ?? $value->type),
            );
            $function->instructions[] = new AssignInstruction($target, $value);

            return $target;
        }

        if ($expression instanceof ArrayExpr) {
            $result = $this->newTemporary((string) $expression->type, $function);
            $elementType = $this->arrayElementType((string) $expression->type);
            $elements = array_map(
                fn (Expr $element): Operand => $this->lowerExpr($element, $function),
                $expression->elements,
            );
            $function->instructions[] = new ArrayInstruction(
                $elementType,
                $elements,
                $result,
            );

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
            $function->instructions[] = new IndexInstruction(
                $array,
                $index,
                $result,
                (string) $expression->type,
            );

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
            $function->instructions[] = new SetIndexInstruction(
                $array,
                $index,
                $value,
                (string) $expression->type,
            );

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
            $arguments = array_map(
                fn (Expr $argument): Operand => $this->lowerExpr($argument, $function),
                $expression->arguments,
            );

            if ($expression->type === 'void') {
                $function->instructions[] = new CallInstruction(
                    $expression->name,
                    $arguments,
                    null,
                );

                return new ConstantOperand('void', null);
            }

            $result = $this->newTemporary((string) $expression->type, $function);
            $function->instructions[] = new CallInstruction(
                $expression->name,
                $arguments,
                $result,
            );

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

    private function arrayElementType(string $type): ?string
    {
        if (preg_match('/^array<(int|float|bool|string)>$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function newLabel(): string
    {
        return 'L' . $this->label++;
    }
}
