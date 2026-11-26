<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Backend\C;

use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Native\NativeModule;
use Cyonima\Compiler\Semantic\ClassInfo;
use Cyonima\Compiler\Ir\AppendInstruction;
use Cyonima\Compiler\Ir\ArrayInstruction;
use Cyonima\Compiler\Ir\AssignInstruction;
use Cyonima\Compiler\Ir\BinaryInstruction;
use Cyonima\Compiler\Ir\CallInstruction;
use Cyonima\Compiler\Ir\CaptureExceptionInstruction;
use Cyonima\Compiler\Ir\CellOperand;
use Cyonima\Compiler\Ir\ClosureCallInstruction;
use Cyonima\Compiler\Ir\ClosureCreateInstruction;
use Cyonima\Compiler\Ir\ConstantOperand;
use Cyonima\Compiler\Ir\EchoInstruction;
use Cyonima\Compiler\Ir\ExceptionCheckInstruction;
use Cyonima\Compiler\Ir\GcInstruction;
use Cyonima\Compiler\Ir\HasIndexInstruction;
use Cyonima\Compiler\Ir\IndexInstruction;
use Cyonima\Compiler\Ir\Instruction;
use Cyonima\Compiler\Ir\IrFunction;
use Cyonima\Compiler\Ir\IrClosure;
use Cyonima\Compiler\Ir\IrMethod;
use Cyonima\Compiler\Ir\IrParam;
use Cyonima\Compiler\Ir\IrProgram;
use Cyonima\Compiler\Ir\JumpIfFalseInstruction;
use Cyonima\Compiler\Ir\JumpIfTrueInstruction;
use Cyonima\Compiler\Ir\JumpInstruction;
use Cyonima\Compiler\Ir\LabelInstruction;
use Cyonima\Compiler\Ir\MapIndexInstruction;
use Cyonima\Compiler\Ir\MapInstruction;
use Cyonima\Compiler\Ir\MapKeyInstruction;
use Cyonima\Compiler\Ir\MapValueInstruction;
use Cyonima\Compiler\Ir\MethodCallInstruction;
use Cyonima\Compiler\Ir\NewInstruction;
use Cyonima\Compiler\Ir\Operand;
use Cyonima\Compiler\Ir\ParentCallInstruction;
use Cyonima\Compiler\Ir\PropertyGetInstruction;
use Cyonima\Compiler\Ir\PropertySetInstruction;
use Cyonima\Compiler\Ir\ReturnInstruction;
use Cyonima\Compiler\Ir\SetIndexInstruction;
use Cyonima\Compiler\Ir\SetMapIndexInstruction;
use Cyonima\Compiler\Ir\StaticCallInstruction;
use Cyonima\Compiler\Ir\StaticPropertyGetInstruction;
use Cyonima\Compiler\Ir\StaticPropertySetInstruction;
use Cyonima\Compiler\Ir\TemporaryOperand;
use Cyonima\Compiler\Ir\ThrowInstruction;
use Cyonima\Compiler\Ir\UnaryInstruction;
use Cyonima\Compiler\Ir\UncaughtExceptionInstruction;
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
        'ui_click',
        'ui_on_click_closure',
        'ui_on_activate_closure',
        'ui_after_closure',
        'ui_quit',
        'ui_run',
    ];

    /** @var array<string, ClassInfo> */
    private array $classes = [];

    /** @var array<string, NativeModule> */
    private array $nativeModules = [];

    /** @var null|array<string, string> */
    private ?array $closureCaptures = null;

    /** @var array<string, string> */
    private array $closureByRefCaptures = [];

    /** @var array<string, true> */
    private array $boxedVariables = [];

    private ?string $currentReturnType = null;

    public function usesUi(IrProgram $program): bool
    {
        foreach ($this->allFunctions($program) as $function) {
            foreach ($function->instructions as $instruction) {
                if ($instruction instanceof CallInstruction
                    && in_array($instruction->name, self::UI_FUNCTIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<IrFunction> */
    private function allFunctions(IrProgram $program): array
    {
        $functions = $program->functions;

        foreach ($program->methods as $method) {
            $functions[] = $method->function;
        }

        foreach ($program->closures as $closure) {
            $functions[] = $closure->function;
        }

        $functions[] = $program->main;

        return $functions;
    }

    /**
     * @param array<string, ClassInfo> $classes
     * @param array<string, NativeModule> $nativeModules
     */
    public function generate(
        IrProgram $program,
        ?bool $ui = null,
        array $classes = [],
        array $nativeModules = [],
    ): string {
        $this->classes = $classes;
        $this->nativeModules = $nativeModules;
        $ui ??= $this->usesUi($program);
        $sections = [];
        $sections[] = <<<'C'
            /*
             * Programme natif généré par le compilateur Cyonima.
             * Sous-ensemble PHP strict — backend C11.
             */

            #ifndef _POSIX_C_SOURCE
            #define _POSIX_C_SOURCE 200809L
            #endif

            #include "cyonima_runtime.h"

            C;

        if ($ui) {
            $sections[] = '#include "cyonima_ui.h"';
        }

        foreach ($this->nativeModules as $module) {
            foreach ($module->includes() as $include) {
                $sections[] = $include;
            }

            $source = trim($module->source());

            if ($source !== '') {
                $sections[] = $source;
            }
        }

        $sections[] = $this->objectHeaderCode();

        foreach ($this->classes as $class) {
            $sections[] = $this->classCode($class);
        }

        $staticCode = $this->staticPropertiesCode();

        if ($staticCode !== '') {
            $sections[] = $staticCode;
        }

        foreach ($program->closures as $closure) {
            $sections[] = $this->closureStructCode($closure);
        }

        foreach ($program->functions as $function) {
            $sections[] = $this->prototype($function);
        }

        foreach ($program->methods as $method) {
            $sections[] = $this->prototype($method->function);
        }

        foreach ($program->closures as $closure) {
            $sections[] = $this->closurePrototype($closure);
        }

        foreach ($this->classes as $class) {
            $sections[] = $this->vtableCode($class);
        }

        foreach ($this->classes as $class) {
            if ($class->isInterface) {
                continue;
            }

            $interfaceCode = $this->interfaceVtableCode($class);

            if ($interfaceCode !== '') {
                $sections[] = $interfaceCode;
            }
        }

        if ($program->functions !== [] || $program->methods !== []
            || $program->closures !== []) {
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

        foreach ($program->methods as $method) {
            $sections[] = $this->functionCode($method->function);
        }

        foreach ($program->closures as $closure) {
            $sections[] = $this->closureCode($closure);
        }

        $sections[] = $this->mainCode($program->main);

        return implode("\n", $sections) . "\n";
    }

    private function interfaceCount(): int
    {
        $count = 0;

        foreach ($this->classes as $class) {
            if ($class->isInterface) {
                $count++;
            }
        }

        return $count;
    }

    private function objectHeaderCode(): string
    {
        $slots = max(1, $this->interfaceCount());

        return "typedef struct {\n"
            . "    void *cy_vtable;\n"
            . "    void *cy_ifaces[" . $slots . "];\n"
            . "} CyObjectHeader;";
    }

    private function classCode(ClassInfo $class): string
    {
        $type = $this->classType($class->name);
        $lines = [
            'typedef struct ' . $type . ' ' . $type . ';',
            'struct ' . $type . ' {',
            '    CyObjectHeader header;',
        ];

        foreach ($class->properties as $name => $propertyType) {
            $lines[] = '    ' . $this->cType($propertyType) . ' '
                . $this->property($name) . ';';
        }

        $lines[] = '};';
        $lines[] = '';
        $lines[] = 'static void cy_obj_mark_' . $this->identifier($class->name)
            . '(void *object)';
        $lines[] = '{';
        $lines[] = '    ' . $type . ' *self = (' . $type . ' *) object;';
        $hasReference = false;

        foreach ($class->properties as $name => $propertyType) {
            if ($this->isGcReference($propertyType)) {
                $lines[] = '    cy_mark_pointer((void *) self->'
                    . $this->property($name) . ');';
                $hasReference = true;
            }
        }

        if (!$hasReference) {
            $lines[] = '    (void) self;';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function staticPropertyName(string $class, string $property): string
    {
        return 'cy_static_' . $this->identifier($class) . '_' . $this->identifier($property);
    }

    private function staticPropertiesCode(): string
    {
        $lines = [];

        foreach ($this->classes as $class) {
            if ($class->isInterface) {
                continue;
            }

            foreach ($class->staticProperties as $name => $type) {
                $owner = $class->staticPropertyOwners[$name] ?? $class->name;

                if ($owner !== $class->name) {
                    continue;
                }

                $lines[] = 'static ' . $this->cType($type) . ' '
                    . $this->staticPropertyName($owner, $name)
                    . ' = ' . $this->staticDefaultValue($type) . ';';
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    private function staticInitCode(): string
    {
        $lines = [];

        foreach ($this->classes as $class) {
            if ($class->isInterface) {
                continue;
            }

            foreach ($class->staticProperties as $name => $type) {
                $owner = $class->staticPropertyOwners[$name] ?? $class->name;

                if ($owner !== $class->name) {
                    continue;
                }

                $global = $this->staticPropertyName($owner, $name);

                if ($this->isListType($type)) {
                    $lines[] = '    ' . $global . ' = cy_array_new();';
                } elseif ($this->isMapType($type)) {
                    $lines[] = '    ' . $global . ' = cy_map_new();';
                }

                if ($this->rootKind($type) !== null) {
                    $lines[] = '    cy_add_root_slot((void *) &' . $global . ');';
                }
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    private function staticDefaultValue(string $type): string
    {
        if ($this->isNullableType($type)) {
            return 'NULL';
        }

        if ($this->isCallableType($type)
            || isset($this->classes[$type])
            || $this->isListType($type)
            || $this->isMapType($type)) {
            return 'NULL';
        }

        return $this->defaultValue($type);
    }

    private function closureType(IrClosure $closure): string
    {
        return 'CyClosureData_' . $closure->id;
    }

    private function closureFunctionName(IrClosure $closure): string
    {
        return 'cy_closure_' . $closure->id;
    }

    private function closureStructCode(IrClosure $closure): string
    {
        $type = $this->closureType($closure);
        $lines = ['typedef struct {', '    void *invoke;'];

        foreach ($closure->captures as $name => $captureType) {
            $pointer = isset($closure->capturesByRef[$name]) ? ' *' : '';
            $lines[] = '    ' . $this->cType($captureType) . $pointer . ' cap_'
                . $this->identifier($name) . ';';
        }

        $lines[] = '} ' . $type . ';';
        $lines[] = '';
        $lines[] = 'static void cy_closure_mark_' . $closure->id . '(void *object)';
        $lines[] = '{';
        $lines[] = '    ' . $type . ' *data = (' . $type . ' *) object;';
        $hasReference = false;

        foreach ($closure->captures as $name => $captureType) {
            if (isset($closure->capturesByRef[$name])) {
                $lines[] = '    cy_mark_pointer((void *) data->cap_'
                    . $this->identifier($name) . ');';
                $hasReference = true;
                continue;
            }

            if ($this->isGcReference($captureType)) {
                $lines[] = '    cy_mark_pointer((void *) data->cap_'
                    . $this->identifier($name) . ');';
                $hasReference = true;
            }
        }

        if (!$hasReference) {
            $lines[] = '    (void) data;';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function closurePrototype(IrClosure $closure): string
    {
        $params = ['void *env'];

        foreach ($closure->params as $param) {
            $params[] = $this->cType($param->type);
        }

        return 'static ' . $this->cType($closure->returnType) . ' '
            . $this->closureFunctionName($closure)
            . '(' . implode(', ', $params) . ');';
    }

    private function closureCode(IrClosure $closure): string
    {
        $type = $this->closureType($closure);
        $data = 'cy_data_' . $closure->id;
        $params = ['void *env'];

        foreach ($closure->params as $param) {
            $params[] = $this->cType($param->type) . ' ' . $this->parameterVariable($param->name);
        }

        $captures = [];
        $this->closureByRefCaptures = [];

        foreach ($closure->captures as $name => $_captureType) {
            $field = $data . '->cap_' . $this->identifier($name);

            if (isset($closure->capturesByRef[$name])) {
                $this->closureByRefCaptures[$name] = $field;
            } else {
                $captures[$name] = $field;
            }
        }

        $this->boxedVariables = $closure->function->boxedVariables;
        $this->closureCaptures = $captures;
        $this->currentReturnType = $closure->returnType;

        $lines = [
            'static ' . $this->cType($closure->returnType) . ' '
                . $this->closureFunctionName($closure)
                . '(' . implode(', ', $params) . ')',
            '{',
            '    ' . $type . ' *' . $data . ' = (' . $type . ' *) env;',
        ];
        $this->declarations($closure->function, $lines);
        $this->rootsCode($closure->function, 'closure_' . $closure->id, $lines);

        foreach ($closure->function->instructions as $instruction) {
            $lines[] = $this->instruction($instruction, '    ');
        }

        $this->closureCaptures = null;
        $this->closureByRefCaptures = [];
        $this->boxedVariables = [];
        $this->currentReturnType = null;
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function isGcReference(string $type): bool
    {
        $type = $this->unwrapNullableType($type);

        return $type === 'string'
            || $this->isListType($type)
            || $this->isMapType($type)
            || isset($this->classes[$type])
            || $this->isCallableType($type);
    }

    private function isCallableType(string $type): bool
    {
        return $type === 'callable' || preg_match(
            '/^callable\(([^)]*)\):(int|float|bool|string|void)$/',
            $type,
        ) === 1;
    }

    private function vtableType(ClassInfo $class): string
    {
        return 'CyVTable_' . $this->identifier($class->name);
    }

    private function vtableCode(ClassInfo $class): string
    {
        $type = $this->vtableType($class);
        $count = count($class->vtable);
        $slots = max(1, $count);
        $lines = [
            'typedef struct {',
            '    CyVTableSlot slots[' . $slots . '];',
            '} ' . $type . ';',
        ];

        if ($class->isInterface) {
            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'static const ' . $type . ' cy_vtable_' . $this->identifier($class->name) . ' = {';

        if ($count === 0) {
            $lines[] = '    { NULL },';
        } else {
            $lines[] = '    {';

            foreach ($class->vtable as [$owner, $method]) {
                $lines[] = $owner === null
                    ? '        NULL,'
                    : '        (CyVTableSlot) ' . $this->methodFunctionName($owner, $method) . ',';
            }

            $lines[] = '    },';
        }

        $lines[] = '};';

        return implode("\n", $lines);
    }

    private function interfaceVtableCode(ClassInfo $class): string
    {
        $lines = [];

        foreach ($class->interfaceVtables as $interfaceName => $data) {
            $interface = $this->classes[$interfaceName] ?? null;

            if ($interface === null || $data['vtable'] === []) {
                continue;
            }

            $type = $this->vtableType($interface);
            $constant = 'cy_iface_' . $this->identifier($class->name)
                . '_' . $this->identifier($interfaceName);
            $lines[] = 'static const ' . $type . ' ' . $constant . ' = {';
            $lines[] = '    {';

            foreach ($data['vtable'] as [$owner, $method]) {
                $lines[] = $owner === null
                    ? '        NULL,'
                    : '        (CyVTableSlot) ' . $this->methodFunctionName($owner, $method) . ',';
            }

            $lines[] = '    },';
            $lines[] = '};';
            $lines[] = '';
        }

        return $lines === [] ? '' : rtrim(implode("\n", $lines));
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
            $params[] = $this->cType($param->type) . ' '
                . $this->parameterVariable($param->name);
        }

        $lines[] = 'static ' . $this->cType($function->returnType) . ' '
            . $this->functionName($function->name)
            . '(' . implode(', ', $params) . ')';
        $lines[] = '{';
        $this->boxedVariables = $function->boxedVariables;
        $this->closureCaptures = null;
        $this->closureByRefCaptures = [];
        $this->declarations($function, $lines);
        $this->rootsCode($function, $function->name, $lines);
        $this->currentReturnType = $function->returnType;

        foreach ($function->instructions as $instruction) {
            $lines[] = $this->instruction($instruction, '    ');
        }

        $this->currentReturnType = null;
        $this->boxedVariables = [];
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function mainCode(IrFunction $main): string
    {
        $lines = ['int main(void)', '{', '    cy_runtime_init();'];
        $staticInit = $this->staticInitCode();

        if ($staticInit !== '') {
            $lines[] = $staticInit;
        }

        $this->boxedVariables = $main->boxedVariables;
        $this->closureCaptures = null;
        $this->closureByRefCaptures = [];
        $this->declarations($main, $lines);
        $this->rootsCode($main, 'main', $lines);
        $this->currentReturnType = 'int';

        foreach ($main->instructions as $instruction) {
            $lines[] = $this->instruction($instruction, '    ');
        }

        $this->currentReturnType = null;
        $this->boxedVariables = [];
        $lines[] = '    return 0;';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function declarations(IrFunction $function, array &$lines): void
    {
        foreach ($function->params as $param) {
            if (!isset($function->boxedVariables[$param->name])) {
                continue;
            }

            $type = $this->cType($param->type);
            $cell = $this->cellVariable($param->name);
            $lines[] = '    ' . $type . ' *' . $cell . ' = (' . $type . ' *) '
                . $this->cellAllocation($param->type) . ';';
            $lines[] = '    *' . $cell . ' = '
                . $this->parameterVariable($param->name) . ';';
        }

        foreach ($function->variables as $name => $type) {
            if (isset($function->boxedVariables[$name])) {
                $ctype = $this->cType($type);
                $cell = $this->cellVariable($name);
                $lines[] = '    ' . $ctype . ' *' . $cell . ' = (' . $ctype . ' *) '
                    . $this->cellAllocation($type) . ';';
                $lines[] = '    *' . $cell . ' = ' . $this->defaultValue($type) . ';';
                continue;
            }

            $lines[] = '    ' . $this->cType($type) . ' ' . $this->variable($name)
                . ' = ' . $this->defaultValue($type) . ';';
        }

        foreach ($function->temporaries as $id => $type) {
            $lines[] = '    ' . $this->cType($type) . ' t_' . $id
                . ' = ' . $this->defaultValue($type) . ';';
        }
    }

    private function cellAllocation(string $type): string
    {
        $ctype = $this->cType($type);

        if ($type === 'string') {
            return 'cy_object_new(sizeof(' . $ctype . '), cy_cell_mark_string)';
        }

        if ($this->isGcReference($type)) {
            return 'cy_object_new(sizeof(' . $ctype . '), cy_cell_mark_pointer)';
        }

        return 'cy_object_new(sizeof(' . $ctype . '), NULL)';
    }

    private function cellVariable(string $name): string
    {
        return 'vc_' . $this->identifier($name);
    }

    private function parameterVariable(string $name): string
    {
        return 'v_' . $this->identifier($name);
    }

    /** @param list<string> $lines */
    private function rootsCode(IrFunction $function, string $id, array &$lines): void
    {
        $roots = [];

        foreach ($function->params as $param) {
            if (isset($function->boxedVariables[$param->name])) {
                $roots[] = ['CY_ROOT_OBJECT', '&' . $this->cellVariable($param->name)];
                continue;
            }

            $kind = $this->rootKind($param->type);

            if ($kind !== null) {
                $roots[] = [$kind, '&' . $this->variable($param->name)];
            }
        }

        foreach ($function->variables as $name => $type) {
            if (isset($function->boxedVariables[$name])) {
                $roots[] = ['CY_ROOT_OBJECT', '&' . $this->cellVariable($name)];
                continue;
            }

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
        $type = $this->unwrapNullableType($type);

        if ($type === 'string') {
            return 'CY_ROOT_STRING';
        }

        if ($this->isCallableType($type)) {
            return 'CY_ROOT_OBJECT';
        }

        if (isset($this->classes[$type])) {
            return 'CY_ROOT_OBJECT';
        }

        if ($this->isMapType($type)) {
            return 'CY_ROOT_MAP';
        }

        if ($this->isListType($type)) {
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
            $exceptionCheck = "    if (cy_exception_pending()) {\n"
                . "        fprintf(stderr, \"cyonima: exception non capturée dans un gestionnaire UI : %s\\n\", cy_exception_message());\n"
                . "        exit(1);\n"
                . "    }\n";

            if (isset($kinds['widget'])) {
                $sections[] = "static void cy_ui_widget_{$id}(GtkWidget *widget, gpointer data)\n"
                    . "{\n"
                    . "    (void) widget;\n"
                    . $dataUse
                    . "    {$call};\n"
                    . $exceptionCheck
                    . "}";
            }

            if (isset($kinds['timer'])) {
                $sections[] = "static gboolean cy_ui_timer_{$id}(gpointer data)\n"
                    . "{\n"
                    . $dataUse
                    . "    {$call};\n"
                    . $exceptionCheck
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

        foreach ($this->allFunctions($program) as $function) {
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
            if ($this->isMapType($instruction->value->type)) {
                $value = 'cy_map_copy(' . $this->operand($instruction->value) . ')';
            } elseif ($this->isListType($instruction->value->type)) {
                $value = 'cy_array_copy(' . $this->operand($instruction->value) . ')';
            } else {
                $value = $this->castOperand($instruction->value, $instruction->target->type);
            }

            return $indent . $this->operand($instruction->target) . ' = ' . $value . ';';
        }

        if ($instruction instanceof ArrayInstruction) {
            $lines = [];

            if ($instruction->elementType !== null) {
                foreach ($instruction->elements as $element) {
                    $lines[] = 'cy_array_push_'
                        . $this->arrayElementSuffix($instruction->elementType)
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
                . ' = cy_array_get_' . $this->arrayElementSuffix($instruction->elementType)
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->index) . ');';
        }

        if ($instruction instanceof AppendInstruction) {
            return $indent . 'cy_array_push_'
                . $this->arrayElementSuffix($instruction->elementType)
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof SetIndexInstruction) {
            return $indent . 'cy_array_set_'
                . $this->arrayElementSuffix($instruction->elementType)
                . '(' . $this->operand($instruction->array) . ', '
                . $this->operand($instruction->index) . ', '
                . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof MapInstruction) {
            $lines = [];

            foreach ($instruction->entries as [$key, $value]) {
                $lines[] = 'cy_map_set_string_'
                    . $this->arrayElementSuffix($instruction->valueType)
                    . '(' . $this->operand($instruction->result) . ', '
                    . $this->operand($key) . ', '
                    . $this->operand($value) . ');';
            }

            if ($lines === []) {
                return $indent . '/* tableau associatif vide */';
            }

            return $indent . implode("\n" . $indent, $lines);
        }

        if ($instruction instanceof MapIndexInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = cy_map_get_string_'
                . $this->arrayElementSuffix($instruction->valueType)
                . '(' . $this->operand($instruction->map) . ', '
                . $this->operand($instruction->key) . ');';
        }

        if ($instruction instanceof SetMapIndexInstruction) {
            return $indent . 'cy_map_set_string_'
                . $this->arrayElementSuffix($instruction->valueType)
                . '(' . $this->operand($instruction->map) . ', '
                . $this->operand($instruction->key) . ', '
                . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof MapKeyInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = cy_map_key_string(' . $this->operand($instruction->map) . ', '
                . $this->operand($instruction->index) . ');';
        }

        if ($instruction instanceof MapValueInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = cy_map_value_'
                . $this->arrayElementSuffix($instruction->valueType)
                . '(' . $this->operand($instruction->map) . ', '
                . $this->operand($instruction->index) . ');';
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
            if ($instruction->value === null) {
                return $indent . 'return;';
            }

            $value = $this->currentReturnType !== null
                ? $this->castOperand($instruction->value, $this->currentReturnType)
                : $this->operand($instruction->value);

            return $indent . 'return ' . $value . ';';
        }

        if ($instruction instanceof GcInstruction) {
            return $indent . 'cy_gc_maybe_collect();';
        }

        if ($instruction instanceof ThrowInstruction) {
            return $indent . 'cy_throw_exception(' . $this->operand($instruction->value) . ');';
        }

        if ($instruction instanceof ClosureCreateInstruction) {
            $closure = $instruction->closure;
            $type = $this->closureType($closure);
            $data = 'cy_data_' . $closure->id;
            $lines = [
                $type . ' *' . $data . ' = (' . $type . ' *) cy_object_new(sizeof('
                    . $type . '), cy_closure_mark_' . $closure->id . ');',
                $data . '->invoke = (void *) ' . $this->closureFunctionName($closure) . ';',
            ];
            $captureNames = array_keys($closure->captures);

            foreach ($captureNames as $index => $name) {
                $captureType = $closure->captures[$name];
                $capture = $instruction->captures[$index] ?? throw new CompilerException(
                    'capture de closure manquante : ' . $name,
                );

                if (isset($closure->capturesByRef[$name])) {
                    $value = $this->operand($capture);
                } elseif ($this->isMapType($captureType)) {
                    $value = 'cy_map_copy(' . $this->operand($capture) . ')';
                } elseif ($this->isListType($captureType)) {
                    $value = 'cy_array_copy(' . $this->operand($capture) . ')';
                } else {
                    $value = $this->castOperand($capture, $captureType);
                }

                $lines[] = $data . '->cap_' . $this->identifier($name) . ' = ' . $value . ';';
            }

            $lines[] = $this->operand($instruction->result) . ' = (CyClosure *) ' . $data . ';';

            return $indent . implode("\n" . $indent, $lines);
        }

        if ($instruction instanceof ClosureCallInstruction) {
            $parameters = ['void *'];

            foreach ($instruction->parameterTypes as $parameterType) {
                $parameters[] = $this->cType($parameterType);
            }

            $arguments = [];

            foreach ($instruction->arguments as $index => $argument) {
                $arguments[] = $this->castOperand(
                    $argument,
                    $instruction->parameterTypes[$index] ?? $argument->type,
                );
            }
            $closure = $this->operand($instruction->closure);
            $call = '((' . $this->cType($instruction->returnType) . ' (*)('
                . implode(', ', $parameters) . ')) '
                . $closure . '->invoke)('
                . $closure
                . ($arguments === [] ? '' : ', ' . implode(', ', $arguments))
                . ')';
            $statement = $instruction->result === null
                ? $call . ';'
                : $this->operand($instruction->result) . ' = ' . $call . ';';

            if ($this->isNullableType($instruction->closure->type)) {
                return $indent . 'if (' . $closure . ' == NULL) { cy_throw_exception("appel d’une closure null"); } else { '
                    . $statement . ' }';
            }

            return $indent . $statement;
        }

        if ($instruction instanceof ParentCallInstruction) {
            $arguments = [];

            foreach ($instruction->arguments as $index => $argument) {
                $arguments[] = $this->valueExpression(
                    $argument,
                    $instruction->parameterTypes[$index] ?? $argument->type,
                );
            }

            $object = $this->castOperand($instruction->object, $instruction->className);
            $call = $this->methodFunctionName($instruction->className, $instruction->method)
                . '(' . $object
                . ($arguments === [] ? '' : ', ' . implode(', ', $arguments))
                . ');';

            if ($instruction->result === null) {
                return $indent . $call;
            }

            return $indent . $this->operand($instruction->result) . ' = ' . $call;
        }

        if ($instruction instanceof NewInstruction) {
            $class = $this->classes[$instruction->className]
                ?? throw new CompilerException(
                    'classe absente du backend : ' . $instruction->className,
                );
            $type = $this->classType($class->name);
            $result = $this->operand($instruction->result);
            $lines = [
                $result . ' = (' . $type . ' *) cy_object_new(sizeof(' . $type . '), '
                    . 'cy_obj_mark_' . $this->identifier($class->name) . ');',
                $result . '->header.cy_vtable = (void *) &cy_vtable_'
                    . $this->identifier($class->name) . ';',
            ];

            foreach ($class->interfaceVtables as $interfaceName => $data) {
                if ($data['vtable'] === []) {
                    continue;
                }

                $lines[] = $result . '->header.cy_ifaces[' . $data['id'] . '] = (void *) &cy_iface_'
                    . $this->identifier($class->name) . '_'
                    . $this->identifier($interfaceName) . ';';
            }

            foreach ($class->properties as $name => $propertyType) {
                if ($propertyType === 'string') {
                    $lines[] = $result . '->' . $this->property($name) . ' = "";';
                } elseif ($this->isListType($propertyType)) {
                    $lines[] = $result . '->' . $this->property($name)
                        . ' = cy_array_new();';
                } elseif ($this->isMapType($propertyType)) {
                    $lines[] = $result . '->' . $this->property($name)
                        . ' = cy_map_new();';
                }
            }

            if ($class->constructor !== null) {
                $constructor = $class->methods[$class->constructor];
                $parameters = array_values($constructor->params);
                $arguments = [];

                foreach ($instruction->arguments as $index => $argument) {
                    $arguments[] = $this->valueExpression(
                        $argument,
                        $parameters[$index] ?? $argument->type,
                    );
                }

                $owner = $class->constructorClass ?? $class->name;
                $lines[] = $this->methodFunctionName($owner, $class->constructor)
                    . '((' . $this->classType($owner) . ' *) ' . $result
                    . ($arguments === [] ? '' : ', ' . implode(', ', $arguments))
                    . ');';
            }

            return $indent . implode("\n" . $indent, $lines);
        }

        if ($instruction instanceof PropertyGetInstruction) {
            $object = $this->operand($instruction->object);
            $access = $object . '->' . $this->property($instruction->property);

            if ($this->isNullableType($instruction->object->type)) {
                return $indent . 'if (' . $object . ' == NULL) { cy_throw_exception("accès à une propriété sur null"); } else { '
                    . $this->operand($instruction->result) . ' = ' . $access . '; }';
            }

            return $indent . $this->operand($instruction->result)
                . ' = ' . $access . ';';
        }

        if ($instruction instanceof PropertySetInstruction) {
            $propertyType = null;

            $objectClass = $this->unwrapNullableType($instruction->object->type);

            if (isset($this->classes[$objectClass])) {
                $propertyType = $this->classes[$objectClass]
                    ->properties[$instruction->property] ?? null;
            }

            $value = $propertyType !== null
                ? $this->valueExpression($instruction->value, $propertyType)
                : $this->operand($instruction->value);
            $object = $this->operand($instruction->object);
            $access = $object . '->' . $this->property($instruction->property)
                . ' = ' . $value;

            if ($this->isNullableType($instruction->object->type)) {
                return $indent . 'if (' . $object . ' == NULL) { cy_throw_exception("affectation de propriété sur null"); } else { '
                    . $access . '; }';
            }

            return $indent . $access . ';';
        }

        if ($instruction instanceof StaticPropertyGetInstruction) {
            return $indent . $this->operand($instruction->result)
                . ' = ' . $this->staticPropertyName(
                    $instruction->className,
                    $instruction->property,
                ) . ';';
        }

        if ($instruction instanceof StaticPropertySetInstruction) {
            $value = $this->valueExpression($instruction->value, $instruction->propertyType);

            return $indent . $this->staticPropertyName(
                $instruction->className,
                $instruction->property,
            ) . ' = ' . $value . ';';
        }

        if ($instruction instanceof StaticCallInstruction) {
            $arguments = [];

            foreach ($instruction->arguments as $index => $argument) {
                $arguments[] = $this->valueExpression(
                    $argument,
                    $instruction->parameterTypes[$index] ?? $argument->type,
                );
            }

            $call = $this->methodFunctionName($instruction->className, $instruction->method)
                . '(' . implode(', ', $arguments) . ');';

            if ($instruction->result === null) {
                return $indent . $call;
            }

            return $indent . $this->operand($instruction->result) . ' = ' . $call;
        }

        if ($instruction instanceof MethodCallInstruction) {
            $class = $this->classes[$instruction->className]
                ?? throw new CompilerException(
                    'classe absente du backend : ' . $instruction->className,
                );
            $method = $class->methods[$instruction->method]
                ?? throw new CompilerException(
                    'méthode absente du backend : ' . $instruction->className . '::' . $instruction->method,
                );
            $slot = $class->vtableSlots[$instruction->method]
                ?? throw new CompilerException(
                    'slot absent pour ' . $instruction->className . '::' . $instruction->method,
                );
            $parameters = ['void *'];
            $arguments = [];
            $parameterTypes = array_values($method->params);

            foreach ($parameterTypes as $index => $parameterType) {
                $parameters[] = $this->cType($parameterType);
                $arguments[] = $this->valueExpression(
                    $instruction->arguments[$index],
                    $parameterType,
                );
            }

            $object = $this->operand($instruction->object);

            if ($class->isInterface) {
                $vtable = '((CyVTable_' . $this->identifier($class->name) . ' *) '
                    . '((CyObjectHeader *) ' . $object . ')->cy_ifaces['
                    . ($class->interfaceId ?? 0) . '])';
            } else {
                $vtable = '((CyVTable_' . $this->identifier($class->name) . ' *) '
                    . $object . '->header.cy_vtable)';
            }

            $call = '((' . $this->cType($method->returnType) . ' (*)('
                . implode(', ', $parameters) . ')) ' . $vtable . '->slots['
                . $slot . '])(' . $object
                . ($arguments === [] ? '' : ', ' . implode(', ', $arguments))
                . ')';

            $statement = $instruction->result === null
                ? $call . ';'
                : $this->operand($instruction->result) . ' = ' . $call . ';';

            if ($this->isNullableType($instruction->object->type)) {
                return $indent . 'if (' . $object . ' == NULL) { cy_throw_exception("appel de méthode sur null"); } else { '
                    . $statement . ' }';
            }

            return $indent . $statement;
        }

        if ($instruction instanceof ExceptionCheckInstruction) {
            return $indent . 'if (cy_exception_pending()) goto ' . $instruction->label . ';';
        }

        if ($instruction instanceof CaptureExceptionInstruction) {
            return $indent . $this->operand($instruction->result) . ' = cy_exception_capture();';
        }

        if ($instruction instanceof UncaughtExceptionInstruction) {
            return $indent
                . 'fprintf(stderr, "cyonima: exception non capturée : %s\\n", cy_exception_message());'
                . "\n" . $indent . 'return 1;';
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

        if ($instruction instanceof JumpIfTrueInstruction) {
            return $indent . 'if (' . $this->operand($instruction->condition) . ') goto '
                . $instruction->label . ';';
        }

        if ($instruction instanceof HasIndexInstruction) {
            $call = $instruction->map
                ? 'cy_map_has_string(' . $this->operand($instruction->collection) . ', '
                    . $this->operand($instruction->key) . ')'
                : 'cy_array_has_int(' . $this->operand($instruction->collection) . ', '
                    . $this->operand($instruction->key) . ')';

            return $indent . $this->operand($instruction->result) . ' = ' . $call . ';';
        }

        throw new CompilerException('instruction IR non supportée par le backend C');
    }

    private function binaryExpression(BinaryInstruction $instruction): string
    {
        $left = $this->operand($instruction->left);
        $right = $this->operand($instruction->right);
        $leftType = $this->unwrapNullableType($instruction->left->type);
        $rightType = $this->unwrapNullableType($instruction->right->type);

        if (in_array($instruction->operator, ['==', '!=', '===', '!=='], true)
            && ($instruction->left->type === 'null' || $instruction->right->type === 'null')) {
            $value = $instruction->left->type === 'null' ? $right : $left;
            $equal = $instruction->operator === '==' || $instruction->operator === '===';
            $operator = $equal ? '==' : '!=';

            return '(' . $value . ' ' . $operator . ' NULL)';
        }

        if (in_array($instruction->operator, ['==', '!=', '===', '!=='], true)
            && ($leftType === 'string' || $rightType === 'string')) {
            $nullSafe = $this->isNullableType($instruction->left->type)
                || $this->isNullableType($instruction->right->type);
            $equal = $instruction->operator === '==' || $instruction->operator === '===';
            $expression = $nullSafe
                ? '(' . $left . ' == NULL || ' . $right . ' == NULL'
                    . ' ? ' . $left . ' == ' . $right
                    . ' : strcmp(' . $left . ', ' . $right . ') == 0)'
                : '(strcmp(' . $left . ', ' . $right . ') == 0)';

            return $equal ? $expression : '(!' . $expression . ')';
        }

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

        if ($instruction->parameterTypes !== null) {
            $arguments = [];

            foreach ($instruction->arguments as $index => $argument) {
                $arguments[] = $this->valueExpression(
                    $argument,
                    $instruction->parameterTypes[$index] ?? $argument->type,
                );
            }
        }

        $data = isset($arguments[2])
            ? '(gpointer)(intptr_t)(' . $arguments[2] . ')'
            : 'NULL';

        return match ($instruction->name) {
            'strlen' => '((cy_int) strlen(' . ($arguments[0] ?? '""') . '))',
            'intval' => $this->conversion('cy_int_from_', $instruction->arguments[0] ?? null),
            'floatval' => $this->conversion('cy_float_from_', $instruction->arguments[0] ?? null),
            'strval' => $this->conversion('cy_string_from_', $instruction->arguments[0] ?? null),
            'abs' => $this->absolute($instruction->arguments[0] ?? null),
            'count' => $this->isMapType($instruction->arguments[0]->type ?? '')
                ? 'cy_map_count(' . ($arguments[0] ?? 'NULL') . ')'
                : 'cy_array_count(' . ($arguments[0] ?? 'NULL') . ')',
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
            'ui_click' => 'cy_ui_click(' . ($arguments[0] ?? '0') . ')',
            'ui_on_click_closure' => 'cy_ui_on_click_closure(' . implode(', ', $arguments) . ')',
            'ui_on_activate_closure' => 'cy_ui_on_activate_closure(' . implode(', ', $arguments) . ')',
            'ui_after_closure' => 'cy_ui_after_closure(' . implode(', ', $arguments) . ')',
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

        $type = $this->unwrapNullableType($operand->type);

        if ($this->isNullableType($operand->type) && $type !== 'string') {
            throw new CompilerException('echo d’une valeur nullable non scalaire non supporté');
        }

        if (isset($this->classes[$type])) {
            throw new CompilerException('echo d’un objet non supporté par le backend C');
        }

        if ($this->isCallableType($type)) {
            throw new CompilerException('echo d’une closure non supporté par le backend C');
        }

        if ($this->isArrayType($type)) {
            throw new CompilerException('echo d’un tableau non supporté par le backend C');
        }

        return 'cy_echo_' . $type . '(' . $this->operand($operand) . ')';
    }

    private function toString(Operand $operand): string
    {
        if ($operand->type === 'string' || $operand->type === '?string') {
            return $this->operand($operand);
        }

        if ($operand->type === 'null') {
            return '""';
        }

        $type = $this->unwrapNullableType($operand->type);

        if (isset($this->classes[$type])) {
            throw new CompilerException('conversion d’un objet en chaîne non supportée');
        }

        if ($this->isCallableType($type)) {
            throw new CompilerException('conversion d’une closure en chaîne non supportée');
        }

        if ($this->isArrayType($type)) {
            throw new CompilerException('conversion d’un tableau en chaîne non supportée');
        }

        return 'cy_string_from_' . $type . '(' . $this->operand($operand) . ')';
    }

    private function operand(Operand $operand): string
    {
        if ($operand instanceof CellOperand) {
            return $this->rawCell($operand->name);
        }

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
            if ($this->isListType($operand->type)) {
                return 'cy_array_new()';
            }

            if ($this->isMapType($operand->type)) {
                return 'cy_map_new()';
            }

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
        $literal = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        if (!is_string($literal)) {
            $literal = sprintf('%.17g', $value);
        }

        if (!str_contains($literal, '.')
            && !str_contains($literal, 'e')
            && !str_contains($literal, 'E')) {
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
        $type = $this->unwrapNullableType($type);

        if ($this->isCallableType($type)) {
            return 'CyClosure *';
        }

        if (isset($this->classes[$type])) {
            return $this->classes[$type]->isInterface
                ? 'CyObjectHeader *'
                : $this->classType($type) . ' *';
        }

        if ($this->isMapType($type)) {
            return 'CyMap *';
        }

        if ($this->isListType($type)) {
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
        if ($this->isNullableType($type)) {
            return 'NULL';
        }

        if ($this->isCallableType($type)) {
            return 'NULL';
        }

        if (isset($this->classes[$type])) {
            return 'NULL';
        }

        if ($this->isMapType($type)) {
            return 'cy_map_new()';
        }

        if ($this->isListType($type)) {
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

    private function isListType(string $type): bool
    {
        return $type === 'array'
            || preg_match('/^array<([^,<>]+)>$/', $type) === 1;
    }

    private function isMapType(string $type): bool
    {
        return preg_match('/^array<string,([^,<>]+)>$/', $type) === 1;
    }

    private function isArrayType(string $type): bool
    {
        return $this->isListType($type) || $this->isMapType($type);
    }

    private function isNullableType(string $type): bool
    {
        return str_starts_with($type, '?');
    }

    private function unwrapNullableType(string $type): string
    {
        return $this->isNullableType($type) ? substr($type, 1) : $type;
    }

    private function arrayElementSuffix(string $type): string
    {
        return isset($this->classes[$type]) ? 'object' : $type;
    }

    private function functionName(string $name): string
    {
        return 'fn_' . $this->identifier($name);
    }

    private function methodFunctionName(string $class, string $method): string
    {
        return 'fn_' . $this->identifier($class) . '__' . $this->identifier($method);
    }

    private function classType(string $name): string
    {
        return 'CyObj_' . $this->identifier($name);
    }

    private function isSubclassOf(string $child, string $parent): bool
    {
        $current = $child;
        $seen = [];

        while ($current !== null && isset($this->classes[$current]) && !isset($seen[$current])) {
            if ($current === $parent) {
                return true;
            }

            $seen[$current] = true;
            $current = $this->classes[$current]->parentName;
        }

        return false;
    }

    private function implementsInterface(string $class, string $interface): bool
    {
        $current = $class;
        $seen = [];

        while ($current !== null && isset($this->classes[$current]) && !isset($seen[$current])) {
            $info = $this->classes[$current];

            if (in_array($interface, $info->interfaces, true)) {
                return true;
            }

            $seen[$current] = true;
            $current = $info->parentName;
        }

        return false;
    }

    private function isAssignable(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if (!isset($this->classes[$actual]) || !isset($this->classes[$expected])) {
            return false;
        }

        if ($this->classes[$expected]->isInterface) {
            return $this->implementsInterface($actual, $expected);
        }

        return $this->isSubclassOf($actual, $expected);
    }

    private function valueExpression(Operand $value, string $targetType): string
    {
        if ($this->isMapType($targetType)) {
            return 'cy_map_copy(' . $this->operand($value) . ')';
        }

        if ($this->isListType($targetType)) {
            return 'cy_array_copy(' . $this->operand($value) . ')';
        }

        return $this->castOperand($value, $targetType);
    }

    private function castOperand(Operand $operand, string $targetType): string
    {
        $value = $this->operand($operand);
        $sourceType = $this->unwrapNullableType($operand->type);
        $targetType = $this->unwrapNullableType($targetType);

        if ($targetType === $sourceType) {
            return $value;
        }

        if (isset($this->classes[$targetType])
            && isset($this->classes[$sourceType])
            && $this->isAssignable($sourceType, $targetType)) {
            if ($this->classes[$targetType]->isInterface) {
                return '(CyObjectHeader *) ' . $value;
            }

            return '(' . $this->classType($targetType) . ' *) ' . $value;
        }

        return $value;
    }

    private function property(string $name): string
    {
        return 'p_' . $this->identifier($name);
    }

    private function variable(string $name): string
    {
        if (isset($this->closureByRefCaptures[$name])) {
            return '(*' . $this->closureByRefCaptures[$name] . ')';
        }

        if ($this->closureCaptures !== null && isset($this->closureCaptures[$name])) {
            return $this->closureCaptures[$name];
        }

        if (isset($this->boxedVariables[$name])) {
            return '(*' . $this->cellVariable($name) . ')';
        }

        return 'v_' . $this->identifier($name);
    }

    private function rawCell(string $name): string
    {
        if (isset($this->closureByRefCaptures[$name])) {
            return $this->closureByRefCaptures[$name];
        }

        if (isset($this->boxedVariables[$name])) {
            return $this->cellVariable($name);
        }

        throw new CompilerException('cellule de capture absente : ' . $name);
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
