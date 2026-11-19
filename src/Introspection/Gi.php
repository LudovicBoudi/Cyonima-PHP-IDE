<?php

/**
 * Accès brut à GObject Introspection (libgirepository).
 *
 * C'est la source de vérité du générateur : les .typelib décrivent les types,
 * les signatures, l'ownership et les signaux des bibliothèques GNOME. Aucun
 * paquet -dev n'est nécessaire, seulement les .typelib du runtime.
 */

declare(strict_types=1);

namespace Cyonima\Introspection;

use FFI;
use FFI\CData;
use RuntimeException;

final class Gi
{
    // GIInfoType (gitypes.h)
    public const INFO_INVALID   = 0;
    public const INFO_FUNCTION  = 1;
    public const INFO_CALLBACK  = 2;
    public const INFO_STRUCT    = 3;
    public const INFO_BOXED     = 4;
    public const INFO_ENUM      = 5;
    public const INFO_FLAGS     = 6;
    public const INFO_OBJECT    = 7;
    public const INFO_INTERFACE = 8;
    public const INFO_CONSTANT  = 9;
    public const INFO_UNION     = 11;
    public const INFO_BITFIELD  = 12;

    // GITypeTag (gitypes.h)
    public const TAG_VOID      = 0;
    public const TAG_BOOLEAN   = 1;
    public const TAG_INT8      = 2;
    public const TAG_UINT8     = 3;
    public const TAG_INT16     = 4;
    public const TAG_UINT16    = 5;
    public const TAG_INT32     = 6;
    public const TAG_UINT32    = 7;
    public const TAG_INT64     = 8;
    public const TAG_UINT64    = 9;
    public const TAG_FLOAT     = 10;
    public const TAG_DOUBLE    = 11;
    public const TAG_GTYPE     = 12;
    public const TAG_UTF8      = 13;
    public const TAG_FILENAME  = 14;
    public const TAG_ARRAY     = 15;
    public const TAG_INTERFACE = 16;
    public const TAG_GLIST     = 17;
    public const TAG_GSLIST    = 18;
    public const TAG_GHASH     = 19;
    public const TAG_ERROR     = 20;
    public const TAG_UNICHAR   = 21;

    // GIDirection
    public const DIRECTION_IN    = 0;
    public const DIRECTION_OUT   = 1;
    public const DIRECTION_INOUT = 2;

    // GITransfer
    public const TRANSFER_NOTHING    = 0;
    public const TRANSFER_CONTAINER  = 1;
    public const TRANSFER_EVERYTHING = 2;

    // GIFunctionInfoFlags
    public const FUNCTION_IS_METHOD      = 1 << 0;
    public const FUNCTION_IS_CONSTRUCTOR = 1 << 1;
    public const FUNCTION_IS_GETTER      = 1 << 2;
    public const FUNCTION_IS_SETTER      = 1 << 3;
    public const FUNCTION_WRAPS_VFUNC    = 1 << 4;
    public const FUNCTION_THROWS         = 1 << 5;

    private const CDEF = <<<'C'
        typedef struct { unsigned int domain; int code; char *message; } GError;

        void       *g_irepository_get_default(void);
        void       *g_irepository_require(void *repo, const char *ns, const char *version, int flags, void **error);
        int         g_irepository_get_n_infos(void *repo, const char *ns);
        void       *g_irepository_get_info(void *repo, const char *ns, int index);
        const char *g_irepository_get_version(void *repo, const char *ns);

        int         g_base_info_get_type(void *info);
        const char *g_base_info_get_name(void *info);
        const char *g_base_info_get_namespace(void *info);
        const char *g_base_info_get_attribute(void *info, const char *name);
        int         g_base_info_is_deprecated(void *info);

        void       *g_object_info_get_parent(void *info);
        const char *g_object_info_get_type_name(void *info);
        int         g_object_info_get_n_methods(void *info);
        void       *g_object_info_get_method(void *info, int n);
        int         g_object_info_get_n_signals(void *info);
        void       *g_object_info_get_signal(void *info, int n);
        int         g_object_info_get_n_properties(void *info);
        void       *g_object_info_get_property(void *info, int n);

        int         g_interface_info_get_n_methods(void *info);
        void       *g_interface_info_get_method(void *info, int n);
        int         g_interface_info_get_n_signals(void *info);
        void       *g_interface_info_get_signal(void *info, int n);

        int         g_callable_info_get_n_args(void *info);
        void       *g_callable_info_get_arg(void *info, int n);
        void       *g_callable_info_get_return_type(void *info);
        int         g_callable_info_get_caller_owns(void *info);
        int         g_callable_info_may_return_null(void *info);
        int         g_callable_info_skip_return(void *info);

        int         g_arg_info_get_direction(void *arg);
        int         g_arg_info_is_return_value(void *arg);
        int         g_arg_info_is_optional(void *arg);
        int         g_arg_info_is_caller_allocates(void *arg);
        int         g_arg_info_get_ownership_transfer(void *arg);
        int         g_arg_info_may_be_null(void *arg);
        void       *g_arg_info_get_type(void *arg);
        int         g_arg_info_get_closure(void *arg);
        int         g_arg_info_get_destroy(void *arg);

        void       *g_type_info_get_interface(void *type);
        int         g_type_info_get_array_length(void *type);
        int         g_type_info_get_array_fixed_size(void *type);
        int         g_type_info_get_array_type(void *type);
        int         g_type_info_get_tag(void *type);
        int         g_type_info_is_pointer(void *type);
        void       *g_type_info_get_param_type(void *type, int n);

        int         g_function_info_get_flags(void *info);
        const char *g_function_info_get_symbol(void *info);

        int         g_signal_info_get_flags(void *info);
        int         g_signal_info_true_stops_emit(void *info);

        int         g_enum_info_get_n_values(void *info);
        void       *g_enum_info_get_value(void *info, int n);
        long long   g_value_info_get_value(void *info);
        C;

    private static ?FFI $ffi = null;
    private static ?CData $repository = null;

    /** @var array<string, string> namespace → version chargée */
    private array $loaded = [];

    public function __construct()
    {
        self::$ffi ??= FFI::cdef(self::CDEF, 'libgirepository-1.0.so.1');
        self::$repository ??= self::$ffi->g_irepository_get_default();
    }

    public function require(string $namespace, string $version): void
    {
        if (isset($this->loaded[$namespace])) {
            return;
        }

        $error = self::$ffi->new('void*[1]', false);
        $error[0] = null;
        $typelib = self::$ffi->g_irepository_require(
            self::$repository,
            $namespace,
            $version,
            0,
            $error
        );

        if ($typelib === null) {
            $message = 'namespace introuvable';
            if ($error[0] !== null) {
                $gerror = self::$ffi->cast('GError*', $error[0]);
                $message = FFI::string($gerror->message);
            }
            throw new RuntimeException(sprintf('%s-%s : %s', $namespace, $version, $message));
        }

        $this->loaded[$namespace] = $version;
    }

    public function count(string $namespace): int
    {
        return self::$ffi->g_irepository_get_n_infos(self::$repository, $namespace);
    }

    /** @return list<CData> */
    public function allInfos(string $namespace): array
    {
        $infos = [];
        $n = $this->count($namespace);
        for ($i = 0; $i < $n; $i++) {
            $infos[] = self::$ffi->g_irepository_get_info(self::$repository, $namespace, $i);
        }
        return $infos;
    }

    public function info(string $namespace, int $index): CData
    {
        return self::$ffi->g_irepository_get_info(self::$repository, $namespace, $index);
    }

    public function infoType(CData $info): int
    {
        return self::$ffi->g_base_info_get_type($info);
    }

    public function infoName(CData $info): string
    {
        return (string) self::$ffi->g_base_info_get_name($info);
    }

    public function infoNamespace(CData $info): string
    {
        return (string) self::$ffi->g_base_info_get_namespace($info);
    }

    public function infoAttribute(CData $info, string $name): ?string
    {
        $value = self::$ffi->g_base_info_get_attribute($info, $name);
        return $value === null ? null : (string) $value;
    }

    public function isDeprecated(CData $info): bool
    {
        return self::$ffi->g_base_info_is_deprecated($info) !== 0;
    }

    public function objectTypeName(CData $objectInfo): ?string
    {
        $name = self::$ffi->g_object_info_get_type_name($objectInfo);
        return $name === null ? null : (string) $name;
    }

    public function parent(CData $objectInfo): ?CData
    {
        $parent = self::$ffi->g_object_info_get_parent($objectInfo);
        return $parent === null ? null : $parent;
    }

    /** @return list<CData> */
    public function methods(CData $info, bool $interface = false): array
    {
        $ffi = self::$ffi;
        $n = $interface
            ? $ffi->g_interface_info_get_n_methods($info)
            : $ffi->g_object_info_get_n_methods($info);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $interface
                ? $ffi->g_interface_info_get_method($info, $i)
                : $ffi->g_object_info_get_method($info, $i);
        }
        return $out;
    }

    /** @return list<CData> */
    public function signals(CData $info, bool $interface = false): array
    {
        $ffi = self::$ffi;
        $n = $interface
            ? $ffi->g_interface_info_get_n_signals($info)
            : $ffi->g_object_info_get_n_signals($info);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $interface
                ? $ffi->g_interface_info_get_signal($info, $i)
                : $ffi->g_object_info_get_signal($info, $i);
        }
        return $out;
    }

    public function functionFlags(CData $functionInfo): int
    {
        return self::$ffi->g_function_info_get_flags($functionInfo);
    }

    public function functionSymbol(CData $functionInfo): string
    {
        return (string) self::$ffi->g_function_info_get_symbol($functionInfo);
    }

    public function signalName(CData $signalInfo): string
    {
        return (string) self::$ffi->g_base_info_get_name($signalInfo);
    }

    /** @return list<CData> */
    public function args(CData $callable): array
    {
        $n = self::$ffi->g_callable_info_get_n_args($callable);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::$ffi->g_callable_info_get_arg($callable, $i);
        }
        return $out;
    }

    public function returnType(CData $callable): CData
    {
        return self::$ffi->g_callable_info_get_return_type($callable);
    }

    public function callerOwns(CData $callable): int
    {
        return self::$ffi->g_callable_info_get_caller_owns($callable);
    }

    public function mayReturnNull(CData $callable): bool
    {
        return self::$ffi->g_callable_info_may_return_null($callable) !== 0;
    }

    public function skipReturn(CData $callable): bool
    {
        return self::$ffi->g_callable_info_skip_return($callable) !== 0;
    }

    public function argName(CData $arg): string
    {
        return (string) self::$ffi->g_arg_info_get_name($arg);
    }

    public function argDirection(CData $arg): int
    {
        return self::$ffi->g_arg_info_get_direction($arg);
    }

    public function argIsReturnValue(CData $arg): bool
    {
        return self::$ffi->g_arg_info_is_return_value($arg) !== 0;
    }

    public function argIsOptional(CData $arg): bool
    {
        return self::$ffi->g_arg_info_is_optional($arg) !== 0;
    }

    public function argIsCallerAllocates(CData $arg): bool
    {
        return self::$ffi->g_arg_info_is_caller_allocates($arg) !== 0;
    }

    public function argTransfer(CData $arg): int
    {
        return self::$ffi->g_arg_info_get_ownership_transfer($arg);
    }

    public function argMayBeNull(CData $arg): bool
    {
        return self::$ffi->g_arg_info_may_be_null($arg) !== 0;
    }

    public function argType(CData $arg): CData
    {
        return self::$ffi->g_arg_info_get_type($arg);
    }

    public function argClosure(CData $arg): int
    {
        return self::$ffi->g_arg_info_get_closure($arg);
    }

    public function argDestroy(CData $arg): int
    {
        return self::$ffi->g_arg_info_get_destroy($arg);
    }

    public function typeTag(CData $type): int
    {
        return self::$ffi->g_type_info_get_tag($type);
    }

    public function typeIsPointer(CData $type): bool
    {
        return self::$ffi->g_type_info_is_pointer($type) !== 0;
    }

    public function typeInterface(CData $type): ?CData
    {
        $iface = self::$ffi->g_type_info_get_interface($type);
        return $iface === null ? null : $iface;
    }

    public function typeArrayLength(CData $type): int
    {
        return self::$ffi->g_type_info_get_array_length($type);
    }

    public function typeArrayFixedSize(CData $type): int
    {
        return self::$ffi->g_type_info_get_array_fixed_size($type);
    }

    public function typeArrayKind(CData $type): int
    {
        return self::$ffi->g_type_info_get_array_type($type);
    }

    public function typeParam(CData $type, int $index): ?CData
    {
        $param = self::$ffi->g_type_info_get_param_type($type, $index);
        return $param === null ? null : $param;
    }

    public function enumValue(CData $valueInfo): int
    {
        return self::$ffi->g_value_info_get_value($valueInfo);
    }

    /** @return list<CData> */
    public function enumValueInfos(CData $info): array
    {
        $n = self::$ffi->g_enum_info_get_n_values($info);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::$ffi->g_enum_info_get_value($info, $i);
        }
        return $out;
    }

    /** @return array<string, int> */
    public function enumValues(CData $info): array
    {
        $ffi = self::$ffi;
        $n = $ffi->g_enum_info_get_n_values($info);

        $values = [];
        for ($i = 0; $i < $n; $i++) {
            $value = $ffi->g_enum_info_get_value($info, $i);
            $name = (string) $ffi->g_base_info_get_name($value);
            $values[$name] = $ffi->g_value_info_get_value($value);
        }
        return $values;
    }
}
