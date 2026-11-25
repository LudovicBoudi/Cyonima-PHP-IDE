<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Backend\C;

use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Ir\AppendInstruction;
use Cyonima\Compiler\Ir\ArrayInstruction;
use Cyonima\Compiler\Ir\AssignInstruction;
use Cyonima\Compiler\Ir\BinaryInstruction;
use Cyonima\Compiler\Ir\CallInstruction;
use Cyonima\Compiler\Ir\ConstantOperand;
use Cyonima\Compiler\Ir\EchoInstruction;
use Cyonima\Compiler\Ir\GcInstruction;
use Cyonima\Compiler\Ir\IndexInstruction;
use Cyonima\Compiler\Ir\Instruction;
use Cyonima\Compiler\Ir\IrFunction;
use Cyonima\Compiler\Ir\IrParam;
use Cyonima\Compiler\Ir\IrProgram;
use Cyonima\Compiler\Ir\JumpIfFalseInstruction;
use Cyonima\Compiler\Ir\JumpInstruction;
use Cyonima\Compiler\Ir\LabelInstruction;
use Cyonima\Compiler\Ir\Operand;
use Cyonima\Compiler\Ir\ReturnInstruction;
use Cyonima\Compiler\Ir\SetIndexInstruction;
use Cyonima\Compiler\Ir\TemporaryOperand;
use Cyonima\Compiler\Ir\UnaryInstruction;
use Cyonima\Compiler\Ir\VariableOperand;

/**
 * Backend C11 de l’IR Cyonima.
 *
 * Le code produit est autonome : un seul fichier C, plus l’en-tête de runtime
 * `runtime/native/cyonima_runtime.h`. Aucune dépendance à PHP ni à GTK.
 */
final class PhpCGenerator
{
    private const UI_FUNCTIONS = [
        'ui_init',
        'ui_window',
        'ui_box',
        'ui_label',
        'ui_button',
        'ui_entry',
        'ui_append',
        'ui_set_child',
        'ui_present',
        'ui_set_text',
        'ui_entry_text',
        'ui_set_visible',
        'ui_on_click',
        'ui_on_activate',
        'ui_after',
        'ui_quit',
        'ui_run',
    ];

    public function usesUi(IrProgram $program): bool
    {
        foreach ([...$program->functions, $program->main] as $function) {
            foreach ($function->instructions as $instruction) {
                if ($instruction instanceof CallInstruction
                    && in_array($instruction->name, self::UI_FUNCTIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function generate(IrProgram $program, ?bool $ui = null): string
    {
        $ui ??= $this->usesUi($program);
        $sections = [];
        $sections[] = <<<'C'
            /*
             * Programme natif généré par le compilateur Cyonima.
             * Sous-ensemble PHP strict — backend C11.
             */

            #include "cyonima_runtime.h"

            C;

        if ($ui) {
            $sections[] = '#include "cyonima_ui.h"';
        }

        foreach ($program->functions as $function) {
            $sections[] = $this->prototype($function);
        }

        if ($program->functions !== []) {
            $sections[] = '';
        }

        if ($ui) {
            $wrappers = $this->handlerWrappers($program);

            if ($wrappers !== '') {
                $sections[] = $wrappers;
            }
        }

        foreach ($program->functions as $function) {
            $sections[] = $this->functionCode($function);
        }

        $sections[] = $this->mainCode($program->main);

        return implode("\n", $sections) . "\n";
    }

    private function prototype(IrFunction $function): string
    {
        $params = array_map(
            fn (IrParam $param): string => $this->cType($param->type),
            $function->params,
        );

        return 'static ' . $this->cType($function->returnType) . ' '
            . $this->functionName($function->name)
            . '(' . implode(', ', $params) . ');';
    }

    private function functionCode(IrFunction $function): string
    {
        $lines = [];
        $params = [];

        foreach ($function->params as $param) {
            $params[] = $this->cType($param->type) . ' ' . $this->variable($param->name);
        }

        $lines[] = 'static ' . $this->cType($function->returnType) . ' '
            . $this->functionName($function->name)
            . '(' . implode(', ', $params) . ')';
        $lines[] = '{';
        $this->declarations($function, $lines);
        $this->rootsCode($function, $function->name, $lines);

        foreach ($function->instructions as $instruction) {
            $lines[] = $this->instruction($instruction, '    ');
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function mainCode(IrFunction $main): string
    {
        $lines = ['int main(void)', '{', '    cy_runtime_init();'];
        $this->declarations($main, $lines);
        $this->rootsCode($main, 'main', $lines);

        foreach ($main->instructions as $instruction) {
            $lines[] = $this->instruction($instruction, '    ');
        }

        $lines[] = '    return 0;';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function declarations(IrFunction $function, array &$lines): void
    {
        foreach ($function->variables as $name => $type) {
            $lines[] = '    ' . $this->cType($type) . ' ' . $this->variable($name)
                . ' = ' . $this->defaultValue($type) . ';';
        }

        foreach ($function->temporaries as $id => $type) {
            $lines[] = '    ' . $this->cType($type) . ' t_' . $id
                . ' = ' . $this->defaultValue($type) . ';';
        }
    }

    /** @param list<string> $lines */
    private function rootsCode(IrFunction $function, string $id, array &$lines): void
    {
        $roots = [];

        foreach ($function->params as $param) {
            $kind = $this->rootKind($param->type);

            if ($kind !== null) {
                $roots[] = [$kind, '&' . $this->variable($param->name)];
            }
        }

        foreach ($function->variables as $name => $type) {
            $kind = $this->rootKind($type);

            if ($kind !== null) {
                $roots[] = [$kind, '&' . $this->variable($name)];
            }
        }

        foreach ($function->temporaries as $temporary => $type) {
            $kind = $this->rootKind($type);

            if ($kind !== null) {
                $roots[] = [$kind, '&t_' . $temporary];
            }
        }

        if ($roots === []) {
            return;
        }

        $suffix = $this->identifier($id);
        $rootsName = 'roots_' . $suffix;
        $frameName = 'frame_' . $suffix;
        $lines[] = '    CyRoot ' . $rootsName . '[' . count($roots) . '] = {';

        foreach ($roots as [$kind, $address]) {
            $lines[] = '        {' . $kind . ', (void *) ' . $address . '},';
        }

        $lines[] = '    };';
        $lines[] = '    CyRootFrame ' . $frameName
            . ' __attribute__((cleanup(cy_roots_pop_cleanup)));';
        $lines[] = '    cy_roots_push(&' . $frameName . ', ' . $rootsName
            . ', ' . count($roots) . ');';
    }

    private function rootKind(string $type): ?string
    {
        if ($type === 'string') {
            return 'CY_ROOT_STRING';
        }

        if ($this->isArrayType($type)) {
            return 'CY_ROOT_ARRAY';
        }

        return null;
    }

    private function handlerWrappers(IrProgram $program): string
    {
        $handlers = $this->uiHandlers($program);
        $signatures = [];

        foreach ($program->functions as $function) {
            $signatures[$function->name] = count($function->params);
        }

        $sections = [];

        foreach ($handlers as $handler => $kinds) {
            $id = $this->identifier($handler);
            $passData = ($signatures[$handler] ?? 0) === 1;
            $call = $this->functionName($handler)
                . ($passData ? '((cy_int)(intptr_t) data)' : '()');
            $dataUse = $passData ? '' : "    (void) data;\n";

            if (isset($kinds['widget'])) {
                $sections[] = "static void cy_ui_widget_{$id}(GtkWidget *widget, gpointer data)\n"
                    . "{\n"
                    . "    (void) widget;\n"
                    . $dataUse
                    . "    {$call};\n"
                    . "}";
            }

            if (isset($kinds['timer'])) {
                $sections[] = "static gboolean cy_ui_timer_{$id}(gpointer data)\n"
                    . "{\n"
                    . $dataUse
                    . "    {$call};\n"
                    . "    return G_SOURCE_REMOVE;\n"
                    . "}";
            }
        }

        return $sections === [] ? '' : implode("\n\n", $sections);
    }

    /** @return array<string, array{widget?: bool, timer?: bool}> */
    private function uiHandlers(IrProgram $program): array
    {
        $handlers = [];

        foreach ([...$program->functions, $program->main] as $function) {
            foreach ($function->instructions as $instruction) {
                if (!$instruction instanceof CallInstruction
                    || !in_array($instruction->name, ['ui_on_click', 'ui_on_activate', 'ui_after'], true)) {
                    continue;
                }

                $handler = $this->constantString($instruction->arguments[1] ?? null);

                if ($handler === null) {
                    continue;
                }

                $kind = $instruction->name === 'ui_after' ? 'timer' : 'widget';
                $handlers[$handler][$kind] = true;
            }
        }

        return $handlers;
    }

    private function constantString(?Operand $operand): ?string
    {
        if ($operand instanceof ConstantOperand
            && $operand->type === 'string'
            && is_string($operand->value)) {
            return $operand->value;
        }

        return null;
    }

    private function instruction(Instruction $instruction, string $indent): string
    {
        if ($instruction instanceof AssignInstruction) {
            $value = $this->isArrayType($instruction->value->type)
                ? 'cy_array_copy(' . $this->operand($instruction->value) . ')'
                : $this->operand($instruction->value);

            return $indent . $this->operand($instruction->target) . ' = ' . $value . ';';
        }

        if ($instruction instanceof ArrayInstruction) {
            $lines = [];

            if ($instruction->elementType !== null) {
                foreach ($instruction->elements as $element) {
                    $lines[] = 'cy_array_push_' . $instruction->elementType
                        . '(' . $this->operand($instruction->result) . ', '
                        . $this->operand($element) . ');';
                }
            }

            if ($lines === []) {
                return $indent . '/* tableau vide */';
            }

            return $indent . implode("\n" . $indent, $lines);
        }

        if ($instruction instanceof IndexInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = cy_array_get_' . $instruction->elementType
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->index) . ');';
        }

        if ($instruction instanceof AppendInstruction) {
            return $indent . 'cy_array_push_' . $instruction->elementType
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof SetIndexInstruction) {
            return $indent . 'cy_array_set_' . $instruction->elementType
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->index) . ', '
                . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof BinaryInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = ' . $this->binaryExpression($instruction) . ';';
        }

        if ($instruction instanceof UnaryInstruction) {
            $operand = $this->operand($instruction->operand);
            $expression = $instruction->operator === '!'
                ? '(!(' . $operand . '))'
                : '(' . $instruction->operator . '(' . $operand . '))';

            return $indent . $this->operand($instruction->result) . ' = ' . $expression . ';';
        }

        if ($instruction instanceof CallInstruction) {
            $call = $this->callExpression($instruction);

            if ($instruction->result === null) {
                return $indent . $call . ';';
            }

            return $indent . $this->operand($instruction->result) . ' = ' . $call . ';';
        }

        if ($instruction instanceof EchoInstruction) {
            return $indent . $this->echoExpression($instruction->value) . ';';
        }

        if ($instruction instanceof ReturnInstruction) {
            return $indent . ($instruction->value === null
                ? 'return;'
                : 'return ' . $this->operand($instruction->value) . ';');
        }

        if ($instruction instanceof GcInstruction) {
            return $indent . 'cy_gc_maybe_collect();';
        }

        if ($instruction instanceof LabelInstruction) {
            return $instruction->label . ':;';
        }

        if ($instruction instanceof JumpInstruction) {
            return $indent . 'goto ' . $instruction->label . ';';
        }

        if ($instruction instanceof JumpIfFalseInstruction) {
            return $indent . 'if (!(' . $this->operand($instruction->condition) . ')) goto '
                . $instruction->label . ';';
        }

        throw new CompilerException('instruction IR non supportée par le backend C');
    }

    private function binaryExpression(BinaryInstruction $instruction): string
    {
        $left = $this->operand($instruction->left);
        $right = $this->operand($instruction->right);

        return match ($instruction->operator) {
            '.' => 'cy_string_concat('
                . $this->toString($instruction->left) . ', '
                . $this->toString($instruction->right) . ')',
            '/' => '((cy_float) (' . $left . ')) / ((cy_float) (' . $right . '))',
            '==', '!=' => $instruction->left->type === 'string'
                ? '(strcmp(' . $left . ', ' . $right . ') '
                    . ($instruction->operator === '==' ? '==' : '!=') . ' 0)'
                : '(' . $left . ' ' . $instruction->operator . ' ' . $right . ')',
            '===', '!==' => $instruction->left->type === 'string'
                ? '(strcmp(' . $left . ', ' . $right . ') '
                    . ($instruction->operator === '===' ? '==' : '!=') . ' 0)'
                : '(' . $left . ' ' . ($instruction->operator === '===' ? '==' : '!=')
                    . ' ' . $right . ')',
            default => '(' . $left . ' ' . $instruction->operator . ' ' . $right . ')',
        };
    }

    private function callExpression(CallInstruction $instruction): string
    {
        $arguments = array_map(fn (Operand $argument): string => $this->operand($argument), $instruction->arguments);
        $data = isset($arguments[2])
            ? '(gpointer)(intptr_t)(' . $arguments[2] . ')'
            : 'NULL';

        return match ($instruction->name) {
            'strlen' => '((cy_int) strlen(' . ($arguments[0] ?? '""') . '))',
            'intval' => $this->conversion('cy_int_from_', $instruction->arguments[0] ?? null),
            'floatval' => $this->conversion('cy_float_from_', $instruction->arguments[0] ?? null),
            'strval' => $this->conversion('cy_string_from_', $instruction->arguments[0] ?? null),
            'abs' => $this->absolute($instruction->arguments[0] ?? null),
            'count' => 'cy_array_count(' . ($arguments[0] ?? 'NULL') . ')',
            'ui_init' => 'cy_ui_init(' . ($arguments[0] ?? '""') . ')',
            'ui_window' => 'cy_ui_window(' . implode(', ', $arguments) . ')',
            'ui_box' => 'cy_ui_box(' . implode(', ', $arguments) . ')',
            'ui_label' => 'cy_ui_label(' . ($arguments[0] ?? '""') . ')',
            'ui_button' => 'cy_ui_button(' . ($arguments[0] ?? '""') . ')',
            'ui_entry' => 'cy_ui_entry(' . ($arguments[0] ?? '""') . ')',
            'ui_append' => 'cy_ui_append(' . implode(', ', $arguments) . ')',
            'ui_set_child' => 'cy_ui_set_child(' . implode(', ', $arguments) . ')',
            'ui_present' => 'cy_ui_present(' . ($arguments[0] ?? '0') . ')',
            'ui_set_text' => 'cy_ui_set_text(' . implode(', ', $arguments) . ')',
            'ui_entry_text' => 'cy_ui_entry_text(' . ($arguments[0] ?? '0') . ')',
            'ui_set_visible' => 'cy_ui_set_visible(' . implode(', ', $arguments) . ')',
            'ui_on_click' => 'cy_ui_on_click(' . ($arguments[0] ?? '0')
                . ', G_CALLBACK(cy_ui_widget_'
                . $this->identifier($this->constantString($instruction->arguments[1] ?? null) ?? '')
                . '), ' . $data . ')',
            'ui_on_activate' => 'cy_ui_on_activate(' . ($arguments[0] ?? '0')
                . ', G_CALLBACK(cy_ui_widget_'
                . $this->identifier($this->constantString($instruction->arguments[1] ?? null) ?? '')
                . '), ' . $data . ')',
            'ui_after' => 'cy_ui_after(' . ($arguments[0] ?? '0')
                . ', G_SOURCE_FUNC(cy_ui_timer_'
                . $this->identifier($this->constantString($instruction->arguments[1] ?? null) ?? '')
                . '), ' . $data . ')',
            'ui_quit' => 'cy_ui_quit()',
            'ui_run' => 'cy_ui_run()',
            default => $this->functionName($instruction->name)
                . '(' . implode(', ', $arguments) . ')',
        };
    }

    private function conversion(string $prefix, ?Operand $operand): string
    {
        if ($operand === null) {
            return '0';
        }

        return $prefix . $operand->type . '(' . $this->operand($operand) . ')';
    }

    private function absolute(?Operand $operand): string
    {
        if ($operand === null) {
            return '0';
        }

        $value = $this->operand($operand);

        return $operand->type === 'float' ? 'fabs(' . $value . ')' : 'llabs(' . $value . ')';
    }

    private function echoExpression(Operand $operand): string
    {
        if ($operand->type === 'null' || $operand->type === 'void') {
            return '(void) 0';
        }

        if ($this->isArrayType($operand->type)) {
            throw new CompilerException('echo d’un tableau non supporté par le backend C');
        }

        return 'cy_echo_' . $operand->type . '(' . $this->operand($operand) . ')';
    }

    private function toString(Operand $operand): string
    {
        if ($operand->type === 'string') {
            return $this->operand($operand);
        }

        if ($operand->type === 'null') {
            return '""';
        }

        if ($this->isArrayType($operand->type)) {
            throw new CompilerException('conversion d’un tableau en chaîne non supportée');
        }

        return 'cy_string_from_' . $operand->type . '(' . $this->operand($operand) . ')';
    }

    private function operand(Operand $operand): string
    {
        if ($operand instanceof ConstantOperand) {
            return $this->constant($operand);
        }

        if ($operand instanceof VariableOperand) {
            return $this->variable($operand->name);
        }

        if ($operand instanceof TemporaryOperand) {
            return 't_' . $operand->id;
        }

        throw new CompilerException('opérande IR non supporté par le backend C');
    }

    private function constant(ConstantOperand $operand): string
    {
        if ($operand->value === null) {
            return 'NULL';
        }

        return match ($operand->type) {
            'int' => (string) (int) $operand->value . 'LL',
            'float' => $this->floatLiteral((float) $operand->value),
            'bool' => ((bool) $operand->value) ? 'true' : 'false',
            'string' => $this->cString((string) $operand->value),
            'void' => 'NULL',
            default => throw new CompilerException('constante IR non supportée : ' . $operand->type),
        };
    }

    private function floatLiteral(float $value): string
    {
        $literal = sprintf('%.17g', $value);

        if (!str_contains($literal, '.') && !str_contains($literal, 'e') && !str_contains($literal, 'E')) {
            $literal .= '.0';
        }

        return $literal;
    }

    private function cString(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new CompilerException('octet nul non supporté dans une chaîne compilée');
        }

        return '"' . str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value,
        ) . '"';
    }

    private function cType(string $type): string
    {
        if ($this->isArrayType($type)) {
            return 'CyArray *';
        }

        return match ($type) {
            'int' => 'cy_int',
            'float' => 'cy_float',
            'bool' => 'bool',
            'string' => 'const char *',
            'void' => 'void',
            default => throw new CompilerException('type C inconnu : ' . $type),
        };
    }

    private function defaultValue(string $type): string
    {
        if ($this->isArrayType($type)) {
            return 'cy_array_new()';
        }

        return match ($type) {
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
            'string' => '""',
            default => throw new CompilerException('type sans valeur par défaut : ' . $type),
        };
    }

    private function isArrayType(string $type): bool
    {
        return $type === 'array' || str_starts_with($type, 'array<');
    }

    private function functionName(string $name): string
    {
        return 'fn_' . $this->identifier($name);
    }

    private function variable(string $name): string
    {
        return 'v_' . $this->identifier($name);
    }

    private function identifier(string $name): string
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? 'value';

        if ($identifier === '' || ctype_digit($identifier[0])) {
            $identifier = '_' . $identifier;
        }

        return $identifier;
    }
}
