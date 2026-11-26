#ifndef CYONIMA_RUNTIME_H
#define CYONIMA_RUNTIME_H

/*
 * Runtime natif minimal du sous-ensemble PHP compilé par Cyonima.
 *
 * Ce fichier est inclus par le C généré. Il ne dépend pas de PHP. Toutes les
 * allocations dynamiques passent par un allocateur centralisé, libéré à la fin
 * du programme via atexit(). La récupération par refcount/GC à l’exécution
 * reste l’étape suivante.
 */

#include <stdbool.h>
#include <math.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef long long cy_int;
typedef double cy_float;

static inline void cy_runtime_out_of_memory(void)
{
    fputs("cyonima: mémoire insuffisante\n", stderr);
    exit(1);
}

typedef void (*CyMarkFunc)(void *);

typedef struct CyAllocation {
    struct CyAllocation *next;
    size_t size;
    int kind;
    bool marked;
    CyMarkFunc mark;
} CyAllocation;

typedef enum {
    CY_ALLOC_BLOB = 0,
    CY_ALLOC_STRING = 1,
    CY_ALLOC_ARRAY = 2,
    CY_ALLOC_MAP = 3,
    CY_ALLOC_OBJECT = 4
} CyAllocationKind;

static CyAllocation *cy_allocations = NULL;
static bool cy_runtime_registered = false;
static int cy_cli_argc = 0;
static char **cy_cli_argv = NULL;
static size_t cy_allocations_since_gc = 0;
static size_t cy_bytes_since_gc = 0;

#define CY_GC_ALLOCATION_THRESHOLD 256
#define CY_GC_BYTE_THRESHOLD (1024 * 1024)

static inline void *cy_alloc(size_t size)
{
    if (size == 0) {
        size = 1;
    }

    CyAllocation *allocation = (CyAllocation *) malloc(sizeof(CyAllocation) + size);

    if (allocation == NULL) {
        cy_runtime_out_of_memory();
    }

    allocation->next = cy_allocations;
    allocation->size = size;
    allocation->kind = CY_ALLOC_BLOB;
    allocation->marked = false;
    allocation->mark = NULL;
    cy_allocations = allocation;
    cy_allocations_since_gc++;
    cy_bytes_since_gc += size;

    return (void *) (allocation + 1);
}

static inline void *cy_alloc_kind(size_t size, CyAllocationKind kind)
{
    void *pointer = cy_alloc(size);
    CyAllocation *allocation = ((CyAllocation *) pointer) - 1;
    allocation->kind = kind;

    return pointer;
}

static inline void *cy_object_new(size_t size, CyMarkFunc mark)
{
    void *object = cy_alloc_kind(size, CY_ALLOC_OBJECT);
    memset(object, 0, size);
    CyAllocation *allocation = ((CyAllocation *) object) - 1;
    allocation->mark = mark;

    return object;
}

static inline void cy_free(void *pointer)
{
    if (pointer == NULL) {
        return;
    }

    CyAllocation *target = ((CyAllocation *) pointer) - 1;
    CyAllocation **current = &cy_allocations;

    while (*current != NULL) {
        if (*current == target) {
            *current = target->next;
            free(target);
            return;
        }

        current = &(*current)->next;
    }
}

static inline void cy_runtime_shutdown(void)
{
    CyAllocation *allocation = cy_allocations;

    while (allocation != NULL) {
        CyAllocation *next = allocation->next;
        free(allocation);
        allocation = next;
    }

    cy_allocations = NULL;
    cy_allocations_since_gc = 0;
    cy_bytes_since_gc = 0;
}

static inline void cy_runtime_init(void)
{
    if (!cy_runtime_registered) {
        cy_runtime_registered = true;
        atexit(cy_runtime_shutdown);
    }
}

#define CY_EXTRA_ROOT_CAPACITY 256

static void *cy_extra_roots[CY_EXTRA_ROOT_CAPACITY];
static size_t cy_extra_root_count = 0;
static void **cy_extra_root_slots[CY_EXTRA_ROOT_CAPACITY];
static size_t cy_extra_root_slot_count = 0;

static inline void cy_add_root(void *pointer)
{
    if (cy_extra_root_count >= CY_EXTRA_ROOT_CAPACITY) {
        fputs("cyonima: capacité de racines supplémentaires atteinte\n", stderr);
        exit(1);
    }

    cy_extra_roots[cy_extra_root_count++] = pointer;
}

static inline void cy_add_root_slot(void *address)
{
    if (cy_extra_root_slot_count >= CY_EXTRA_ROOT_CAPACITY) {
        fputs("cyonima: capacité de racines statiques atteinte\n", stderr);
        exit(1);
    }

    cy_extra_root_slots[cy_extra_root_slot_count++] = (void **) address;
}

static bool cy_class_is_a(int child, int target);

static const char *cy_current_exception = NULL;
static void *cy_current_exception_object = NULL;
static int cy_current_exception_class = -1;
static bool cy_exception_pending_flag = false;

typedef struct {
    const char *message;
    void *object;
    int class_id;
    bool pending;
} CyExceptionState;

#define CY_EXCEPTION_STACK_CAPACITY 16

static CyExceptionState cy_exception_stack[CY_EXCEPTION_STACK_CAPACITY];
static size_t cy_exception_stack_depth = 0;

static inline void cy_throw_exception(const char *message)
{
    cy_current_exception = message == NULL ? "" : message;
    cy_current_exception_object = NULL;
    cy_current_exception_class = -1;
    cy_exception_pending_flag = true;
}

static inline void cy_throw_object(void *object, int class_id, const char *message)
{
    cy_current_exception = message == NULL ? "" : message;
    cy_current_exception_object = object;
    cy_current_exception_class = class_id;
    cy_exception_pending_flag = true;
}

static inline bool cy_exception_pending(void)
{
    return cy_exception_pending_flag;
}

static inline const char *cy_exception_message(void)
{
    return cy_current_exception == NULL ? "" : cy_current_exception;
}

static inline bool cy_exception_is_object(void)
{
    return cy_current_exception_object != NULL;
}

static inline void *cy_exception_object(void)
{
    return cy_current_exception_object;
}

static inline int cy_exception_class(void)
{
    return cy_current_exception_class;
}

static inline bool cy_exception_matches(int class_id)
{
    return cy_exception_is_object() && cy_class_is_a(cy_current_exception_class, class_id);
}

static inline void cy_exception_clear(void)
{
    cy_current_exception = NULL;
    cy_current_exception_object = NULL;
    cy_current_exception_class = -1;
    cy_exception_pending_flag = false;
}

static inline const char *cy_exception_capture(void)
{
    const char *message = cy_exception_message();
    cy_exception_clear();

    return message;
}

static inline void *cy_exception_capture_object(void)
{
    void *object = cy_current_exception_object;
    cy_exception_clear();

    return object;
}

static inline void cy_exception_suspend(void)
{
    if (cy_exception_stack_depth >= CY_EXCEPTION_STACK_CAPACITY) {
        fputs("cyonima: pile d’exceptions saturée\n", stderr);
        exit(1);
    }

    CyExceptionState state;
    state.message = cy_current_exception;
    state.object = cy_current_exception_object;
    state.class_id = cy_current_exception_class;
    state.pending = cy_exception_pending_flag;
    cy_exception_stack[cy_exception_stack_depth++] = state;
    cy_exception_clear();
}

static inline void cy_exception_resume(void)
{
    if (cy_exception_stack_depth == 0) {
        return;
    }

    CyExceptionState state = cy_exception_stack[--cy_exception_stack_depth];
    cy_current_exception = state.message;
    cy_current_exception_object = state.object;
    cy_current_exception_class = state.class_id;
    cy_exception_pending_flag = state.pending;
}

typedef enum {
    CY_VALUE_NULL,
    CY_VALUE_BOOL,
    CY_VALUE_INT,
    CY_VALUE_FLOAT,
    CY_VALUE_STRING,
    CY_VALUE_ARRAY,
    CY_VALUE_MAP,
    CY_VALUE_OBJECT
} CyValueType;

typedef struct CyArray CyArray;
typedef struct CyMap CyMap;

typedef struct {
    CyValueType type;
    union {
        bool boolean;
        cy_int integer;
        cy_float floating;
        const char *string;
        CyArray *array;
        CyMap *map;
        void *object;
    } as;
} CyValue;

struct CyArray {
    size_t length;
    size_t capacity;
    CyValue *items;
};

typedef struct {
    CyValue key;
    CyValue value;
} CyMapEntry;

struct CyMap {
    size_t length;
    size_t capacity;
    CyMapEntry *entries;
};

typedef struct {
    void *invoke;
    int arity;
    int param_kind;
} CyClosure;

typedef void (*CyVTableSlot)(void);

typedef enum {
    CY_ROOT_STRING = 0,
    CY_ROOT_ARRAY = 1,
    CY_ROOT_MAP = 2,
    CY_ROOT_OBJECT = 3
} CyRootType;

typedef struct {
    CyRootType type;
    void *address;
} CyRoot;

typedef struct CyRootFrame {
    CyRoot *roots;
    size_t count;
    struct CyRootFrame *previous;
} CyRootFrame;

static CyRootFrame *cy_root_frames = NULL;

static inline void cy_roots_push(CyRootFrame *frame, CyRoot *roots, size_t count)
{
    frame->roots = roots;
    frame->count = count;
    frame->previous = cy_root_frames;
    cy_root_frames = frame;
}

static inline void cy_roots_pop(CyRootFrame *frame)
{
    if (cy_root_frames == frame) {
        cy_root_frames = frame->previous;
    }
}

static inline void cy_roots_pop_cleanup(CyRootFrame *frame)
{
    cy_roots_pop(frame);
}

static inline void cy_mark_pointer(void *pointer)
{
    if (pointer == NULL) {
        return;
    }

    for (CyAllocation *allocation = cy_allocations; allocation != NULL; allocation = allocation->next) {
        if ((void *) (allocation + 1) != pointer) {
            continue;
        }

        if (allocation->marked) {
            return;
        }

        allocation->marked = true;

        if (allocation->kind == CY_ALLOC_ARRAY) {
            CyArray *array = (CyArray *) pointer;
            cy_mark_pointer(array->items);

            for (size_t i = 0; i < array->length; i++) {
                if (array->items[i].type == CY_VALUE_STRING) {
                    cy_mark_pointer((void *) array->items[i].as.string);
                } else if (array->items[i].type == CY_VALUE_ARRAY) {
                    cy_mark_pointer(array->items[i].as.array);
                } else if (array->items[i].type == CY_VALUE_MAP) {
                    cy_mark_pointer(array->items[i].as.map);
                } else if (array->items[i].type == CY_VALUE_OBJECT) {
                    cy_mark_pointer(array->items[i].as.object);
                }
            }
        } else if (allocation->kind == CY_ALLOC_MAP) {
            CyMap *map = (CyMap *) pointer;
            cy_mark_pointer(map->entries);

            for (size_t i = 0; i < map->length; i++) {
                if (map->entries[i].key.type == CY_VALUE_STRING) {
                    cy_mark_pointer((void *) map->entries[i].key.as.string);
                }

                if (map->entries[i].value.type == CY_VALUE_STRING) {
                    cy_mark_pointer((void *) map->entries[i].value.as.string);
                } else if (map->entries[i].value.type == CY_VALUE_ARRAY) {
                    cy_mark_pointer(map->entries[i].value.as.array);
                } else if (map->entries[i].value.type == CY_VALUE_MAP) {
                    cy_mark_pointer(map->entries[i].value.as.map);
                } else if (map->entries[i].value.type == CY_VALUE_OBJECT) {
                    cy_mark_pointer(map->entries[i].value.as.object);
                }
            }
        } else if (allocation->kind == CY_ALLOC_OBJECT) {
            if (allocation->mark != NULL) {
                allocation->mark(pointer);
            }
        }

        return;
    }
}

static void cy_cell_mark_string(void *cell)
{
    cy_mark_pointer((void *) *(const char **) cell);
}

static void cy_cell_mark_pointer(void *cell)
{
    cy_mark_pointer(*(void **) cell);
}

static inline void cy_gc_collect(void)
{
    cy_mark_pointer((void *) cy_current_exception);
    cy_mark_pointer(cy_current_exception_object);

    for (size_t i = 0; i < cy_exception_stack_depth; i++) {
        cy_mark_pointer((void *) cy_exception_stack[i].message);
        cy_mark_pointer(cy_exception_stack[i].object);
    }

    for (size_t i = 0; i < cy_extra_root_count; i++) {
        cy_mark_pointer(cy_extra_roots[i]);
    }

    for (size_t i = 0; i < cy_extra_root_slot_count; i++) {
        void *value = NULL;
        memcpy(&value, cy_extra_root_slots[i], sizeof(void *));
        cy_mark_pointer(value);
    }

    for (CyRootFrame *frame = cy_root_frames; frame != NULL; frame = frame->previous) {
        for (size_t i = 0; i < frame->count; i++) {
            void *value = NULL;
            memcpy(&value, frame->roots[i].address, sizeof(void *));
            cy_mark_pointer(value);
        }
    }

    CyAllocation **current = &cy_allocations;

    while (*current != NULL) {
        CyAllocation *allocation = *current;

        if (allocation->marked) {
            allocation->marked = false;
            current = &allocation->next;
            continue;
        }

        *current = allocation->next;
        free(allocation);
    }

    cy_allocations_since_gc = 0;
    cy_bytes_since_gc = 0;
}

static inline void cy_gc_maybe_collect(void)
{
    if (cy_allocations_since_gc < CY_GC_ALLOCATION_THRESHOLD
        && cy_bytes_since_gc < CY_GC_BYTE_THRESHOLD) {
        return;
    }

    cy_gc_collect();
}

static inline CyValue cy_value_null(void)
{
    CyValue result;
    result.type = CY_VALUE_NULL;
    result.as.integer = 0;

    return result;
}

static inline CyValue cy_value_bool(bool value)
{
    CyValue result;
    result.type = CY_VALUE_BOOL;
    result.as.boolean = value;

    return result;
}

static inline CyValue cy_value_int(cy_int value)
{
    CyValue result;
    result.type = CY_VALUE_INT;
    result.as.integer = value;

    return result;
}

static inline CyValue cy_value_float(cy_float value)
{
    CyValue result;
    result.type = CY_VALUE_FLOAT;
    result.as.floating = value;

    return result;
}

static inline CyValue cy_value_string(const char *value)
{
    CyValue result;
    result.type = CY_VALUE_STRING;
    result.as.string = value;

    return result;
}

static inline CyValue cy_value_array(CyArray *value)
{
    CyValue result;
    result.type = CY_VALUE_ARRAY;
    result.as.array = value;

    return result;
}

static inline CyValue cy_value_map(CyMap *value)
{
    CyValue result;
    result.type = CY_VALUE_MAP;
    result.as.map = value;

    return result;
}

static inline CyValue cy_value_object(void *value)
{
    CyValue result;
    result.type = CY_VALUE_OBJECT;
    result.as.object = value;

    return result;
}

static inline CyArray *cy_array_new(void)
{
    CyArray *array = (CyArray *) cy_alloc_kind(sizeof(CyArray), CY_ALLOC_ARRAY);
    memset(array, 0, sizeof(CyArray));

    return array;
}

static inline void cy_array_reserve(CyArray *array, size_t needed)
{
    if (needed <= array->capacity) {
        return;
    }

    size_t capacity = array->capacity == 0 ? 4 : array->capacity * 2;

    while (capacity < needed) {
        capacity *= 2;
    }

    CyValue *items = (CyValue *) cy_alloc(capacity * sizeof(CyValue));

    if (array->length > 0) {
        memcpy(items, array->items, array->length * sizeof(CyValue));
    }

    cy_free(array->items);
    array->items = items;
    array->capacity = capacity;
}

static inline void cy_array_push_value(CyArray *array, CyValue value)
{
    cy_array_reserve(array, array->length + 1);
    array->items[array->length++] = value;
}

static inline CyValue cy_array_get_value(CyArray *array, cy_int index)
{
    if (index < 0 || (size_t) index >= array->length) {
        fprintf(stderr, "cyonima: index %lld hors limites (taille %zu)\n", index, array->length);
        exit(1);
    }

    return array->items[index];
}

static inline void cy_array_set_value(CyArray *array, cy_int index, CyValue value)
{
    if (index < 0) {
        fprintf(stderr, "cyonima: index %lld invalide\n", index);
        exit(1);
    }

    if ((size_t) index == array->length) {
        cy_array_push_value(array, value);
        return;
    }

    if ((size_t) index > array->length) {
        fprintf(stderr, "cyonima: index %lld hors limites (taille %zu)\n", index, array->length);
        exit(1);
    }

    array->items[index] = value;
}

static inline cy_int cy_array_count(CyArray *array)
{
    return (cy_int) array->length;
}

static inline bool cy_array_has_int(CyArray *array, cy_int index)
{
    return index >= 0 && (size_t) index < array->length;
}

static inline CyArray *cy_array_copy(CyArray *array)
{
    CyArray *copy = cy_array_new();
    cy_array_reserve(copy, array->length);

    if (array->length > 0) {
        memcpy(copy->items, array->items, array->length * sizeof(CyValue));
    }

    copy->length = array->length;

    return copy;
}

static inline void cy_array_type_error(void)
{
    fputs("cyonima: type d'élément de tableau inattendu\n", stderr);
    exit(1);
}

static inline void cy_array_push_int(CyArray *array, cy_int value)
{
    cy_array_push_value(array, cy_value_int(value));
}

static inline cy_int cy_array_get_int(CyArray *array, cy_int index)
{
    CyValue value = cy_array_get_value(array, index);

    if (value.type != CY_VALUE_INT) {
        cy_array_type_error();
    }

    return value.as.integer;
}

static inline void cy_array_set_int(CyArray *array, cy_int index, cy_int value)
{
    cy_array_set_value(array, index, cy_value_int(value));
}

static inline void cy_array_push_float(CyArray *array, cy_float value)
{
    cy_array_push_value(array, cy_value_float(value));
}

static inline cy_float cy_array_get_float(CyArray *array, cy_int index)
{
    CyValue value = cy_array_get_value(array, index);

    if (value.type != CY_VALUE_FLOAT) {
        cy_array_type_error();
    }

    return value.as.floating;
}

static inline void cy_array_set_float(CyArray *array, cy_int index, cy_float value)
{
    cy_array_set_value(array, index, cy_value_float(value));
}

static inline void cy_array_push_bool(CyArray *array, bool value)
{
    cy_array_push_value(array, cy_value_bool(value));
}

static inline bool cy_array_get_bool(CyArray *array, cy_int index)
{
    CyValue value = cy_array_get_value(array, index);

    if (value.type != CY_VALUE_BOOL) {
        cy_array_type_error();
    }

    return value.as.boolean;
}

static inline void cy_array_set_bool(CyArray *array, cy_int index, bool value)
{
    cy_array_set_value(array, index, cy_value_bool(value));
}

static inline void cy_array_push_string(CyArray *array, const char *value)
{
    cy_array_push_value(array, cy_value_string(value));
}

static inline const char *cy_array_get_string(CyArray *array, cy_int index)
{
    CyValue value = cy_array_get_value(array, index);

    if (value.type != CY_VALUE_STRING) {
        cy_array_type_error();
    }

    return value.as.string;
}

static inline void cy_array_set_string(CyArray *array, cy_int index, const char *value)
{
    cy_array_set_value(array, index, cy_value_string(value));
}

static inline void cy_array_push_object(CyArray *array, void *value)
{
    cy_array_push_value(array, cy_value_object(value));
}

static inline void *cy_array_get_object(CyArray *array, cy_int index)
{
    CyValue value = cy_array_get_value(array, index);

    if (value.type != CY_VALUE_OBJECT) {
        cy_array_type_error();
    }

    return value.as.object;
}

static inline void cy_array_set_object(CyArray *array, cy_int index, void *value)
{
    cy_array_set_value(array, index, cy_value_object(value));
}

static inline CyMap *cy_map_new(void)
{
    CyMap *map = (CyMap *) cy_alloc_kind(sizeof(CyMap), CY_ALLOC_MAP);
    memset(map, 0, sizeof(CyMap));

    return map;
}

static inline void cy_map_reserve(CyMap *map, size_t needed)
{
    if (needed <= map->capacity) {
        return;
    }

    size_t capacity = map->capacity == 0 ? 4 : map->capacity * 2;

    while (capacity < needed) {
        capacity *= 2;
    }

    CyMapEntry *entries = (CyMapEntry *) cy_alloc(capacity * sizeof(CyMapEntry));

    if (map->length > 0) {
        memcpy(entries, map->entries, map->length * sizeof(CyMapEntry));
    }

    cy_free(map->entries);
    map->entries = entries;
    map->capacity = capacity;
}

static inline size_t cy_map_find_string(CyMap *map, const char *key)
{
    for (size_t i = 0; i < map->length; i++) {
        if (map->entries[i].key.type == CY_VALUE_STRING
            && strcmp(map->entries[i].key.as.string, key) == 0) {
            return i;
        }
    }

    return (size_t) -1;
}

static inline void cy_map_type_error(void)
{
    fputs("cyonima: type de valeur associative inattendu\n", stderr);
    exit(1);
}

static inline void cy_map_set_value_string(CyMap *map, const char *key, CyValue value)
{
    size_t index = cy_map_find_string(map, key);

    if (index != (size_t) -1) {
        map->entries[index].value = value;
        return;
    }

    cy_map_reserve(map, map->length + 1);
    map->entries[map->length].key = cy_value_string(key);
    map->entries[map->length].value = value;
    map->length++;
}

static inline CyValue cy_map_get_value_string(CyMap *map, const char *key)
{
    size_t index = cy_map_find_string(map, key);

    if (index == (size_t) -1) {
        fprintf(stderr, "cyonima: clé absente du tableau associatif : %s\n", key);
        exit(1);
    }

    return map->entries[index].value;
}

static inline bool cy_map_has_string(CyMap *map, const char *key)
{
    return cy_map_find_string(map, key) != (size_t) -1;
}

static inline cy_int cy_map_count(CyMap *map)
{
    return (cy_int) map->length;
}

static inline CyMap *cy_map_copy(CyMap *map)
{
    CyMap *copy = cy_map_new();
    cy_map_reserve(copy, map->length);

    if (map->length > 0) {
        memcpy(copy->entries, map->entries, map->length * sizeof(CyMapEntry));
    }

    copy->length = map->length;

    return copy;
}

static inline CyMapEntry cy_map_entry_at(CyMap *map, cy_int index)
{
    if (index < 0 || (size_t) index >= map->length) {
        fprintf(stderr, "cyonima: index associatif %lld hors limites (taille %zu)\n", index, map->length);
        exit(1);
    }

    return map->entries[index];
}

static inline const char *cy_map_key_string(CyMap *map, cy_int index)
{
    CyMapEntry entry = cy_map_entry_at(map, index);

    if (entry.key.type != CY_VALUE_STRING) {
        cy_map_type_error();
    }

    return entry.key.as.string;
}

static inline void cy_map_set_string_int(CyMap *map, const char *key, cy_int value)
{
    cy_map_set_value_string(map, key, cy_value_int(value));
}

static inline cy_int cy_map_get_string_int(CyMap *map, const char *key)
{
    CyValue value = cy_map_get_value_string(map, key);

    if (value.type != CY_VALUE_INT) {
        cy_map_type_error();
    }

    return value.as.integer;
}

static inline cy_int cy_map_value_int(CyMap *map, cy_int index)
{
    CyValue value = cy_map_entry_at(map, index).value;

    if (value.type != CY_VALUE_INT) {
        cy_map_type_error();
    }

    return value.as.integer;
}

static inline void cy_map_set_string_float(CyMap *map, const char *key, cy_float value)
{
    cy_map_set_value_string(map, key, cy_value_float(value));
}

static inline cy_float cy_map_get_string_float(CyMap *map, const char *key)
{
    CyValue value = cy_map_get_value_string(map, key);

    if (value.type != CY_VALUE_FLOAT) {
        cy_map_type_error();
    }

    return value.as.floating;
}

static inline cy_float cy_map_value_float(CyMap *map, cy_int index)
{
    CyValue value = cy_map_entry_at(map, index).value;

    if (value.type != CY_VALUE_FLOAT) {
        cy_map_type_error();
    }

    return value.as.floating;
}

static inline void cy_map_set_string_bool(CyMap *map, const char *key, bool value)
{
    cy_map_set_value_string(map, key, cy_value_bool(value));
}

static inline bool cy_map_get_string_bool(CyMap *map, const char *key)
{
    CyValue value = cy_map_get_value_string(map, key);

    if (value.type != CY_VALUE_BOOL) {
        cy_map_type_error();
    }

    return value.as.boolean;
}

static inline bool cy_map_value_bool(CyMap *map, cy_int index)
{
    CyValue value = cy_map_entry_at(map, index).value;

    if (value.type != CY_VALUE_BOOL) {
        cy_map_type_error();
    }

    return value.as.boolean;
}

static inline void cy_map_set_string_string(CyMap *map, const char *key, const char *value)
{
    cy_map_set_value_string(map, key, cy_value_string(value));
}

static inline const char *cy_map_get_string_string(CyMap *map, const char *key)
{
    CyValue value = cy_map_get_value_string(map, key);

    if (value.type != CY_VALUE_STRING) {
        cy_map_type_error();
    }

    return value.as.string;
}

static inline const char *cy_map_value_string(CyMap *map, cy_int index)
{
    CyValue value = cy_map_entry_at(map, index).value;

    if (value.type != CY_VALUE_STRING) {
        cy_map_type_error();
    }

    return value.as.string;
}

static inline void cy_map_set_string_object(CyMap *map, const char *key, void *value)
{
    cy_map_set_value_string(map, key, cy_value_object(value));
}

static inline void *cy_map_get_string_object(CyMap *map, const char *key)
{
    CyValue value = cy_map_get_value_string(map, key);

    if (value.type != CY_VALUE_OBJECT) {
        cy_map_type_error();
    }

    return value.as.object;
}

static inline void *cy_map_value_object(CyMap *map, cy_int index)
{
    CyValue value = cy_map_entry_at(map, index).value;

    if (value.type != CY_VALUE_OBJECT) {
        cy_map_type_error();
    }

    return value.as.object;
}

static inline const char *cy_strdup(const char *value)
{
    size_t length = strlen(value);
    char *copy = (char *) cy_alloc_kind(length + 1, CY_ALLOC_STRING);
    memcpy(copy, value, length + 1);

    return copy;
}

static inline const char *cy_string_concat(const char *left, const char *right)
{
    if (left == NULL) {
        left = "";
    }

    if (right == NULL) {
        right = "";
    }

    size_t leftLength = strlen(left);
    size_t rightLength = strlen(right);
    char *result = (char *) cy_alloc_kind(leftLength + rightLength + 1, CY_ALLOC_STRING);
    memcpy(result, left, leftLength);
    memcpy(result + leftLength, right, rightLength + 1);

    return result;
}

static inline const char *cy_string_from_string(const char *value)
{
    return value == NULL ? "" : value;
}

static inline const char *cy_string_from_int(cy_int value)
{
    char buffer[32];
    snprintf(buffer, sizeof buffer, "%lld", value);

    return cy_strdup(buffer);
}

static inline const char *cy_string_from_float(cy_float value)
{
    char buffer[64];
    snprintf(buffer, sizeof buffer, "%.14g", value);

    return cy_strdup(buffer);
}

static inline const char *cy_string_from_bool(bool value)
{
    return value ? "1" : "";
}

static inline cy_int cy_int_from_int(cy_int value)
{
    return value;
}

static inline cy_int cy_int_from_float(cy_float value)
{
    return (cy_int) value;
}

static inline cy_int cy_int_from_bool(bool value)
{
    return value ? 1 : 0;
}

static inline cy_int cy_int_from_string(const char *value)
{
    return (cy_int) strtoll(value, NULL, 10);
}

static inline cy_float cy_float_from_int(cy_int value)
{
    return (cy_float) value;
}

static inline cy_float cy_float_from_float(cy_float value)
{
    return value;
}

static inline cy_float cy_float_from_bool(bool value)
{
    return value ? 1.0 : 0.0;
}

static inline cy_float cy_float_from_string(const char *value)
{
    return (cy_float) strtod(value, NULL);
}

static inline void cy_echo_int(cy_int value)
{
    printf("%lld", value);
}

static inline void cy_echo_float(cy_float value)
{
    printf("%.14g", value);
}

static inline void cy_echo_bool(bool value)
{
    if (value) {
        fputc('1', stdout);
    }
}

static inline void cy_echo_string(const char *value)
{
    if (value != NULL) {
        fputs(value, stdout);
    }
}

static inline bool cy_str_starts_with(const char *value, const char *prefix)
{
    if (value == NULL || prefix == NULL) {
        return false;
    }

    size_t prefixLength = strlen(prefix);

    return strncmp(value, prefix, prefixLength) == 0;
}

static inline bool cy_str_contains(const char *value, const char *needle)
{
    if (value == NULL || needle == NULL) {
        return false;
    }

    return strstr(value, needle) != NULL;
}

static inline const char *cy_substr(const char *value, cy_int offset)
{
    if (value == NULL) {
        return "";
    }

    size_t length = strlen(value);

    if (offset < 0 || (size_t) offset >= length) {
        return "";
    }

    return cy_strdup(value + offset);
}

static inline const char *cy_substr_len(const char *value, cy_int offset, cy_int sublength)
{
    if (value == NULL || sublength <= 0) {
        return "";
    }

    size_t length = strlen(value);

    if (offset < 0 || (size_t) offset >= length) {
        return "";
    }

    size_t available = length - (size_t) offset;
    size_t wanted = (size_t) sublength;

    if (wanted > available) {
        wanted = available;
    }

    char *result = (char *) cy_alloc_kind(wanted + 1, CY_ALLOC_STRING);
    memcpy(result, value + offset, wanted);
    result[wanted] = '\0';

    return result;
}

static inline const char *cy_str_trim(const char *value)
{
    if (value == NULL) {
        return "";
    }

    const char *start = value;

    while (*start == ' ' || *start == '\t' || *start == '\n' || *start == '\r') {
        start++;
    }

    const char *end = start + strlen(start);

    while (end > start
        && (end[-1] == ' ' || end[-1] == '\t' || end[-1] == '\n' || end[-1] == '\r')) {
        end--;
    }

    size_t length = (size_t) (end - start);
    char *result = (char *) cy_alloc_kind(length + 1, CY_ALLOC_STRING);
    memcpy(result, start, length);
    result[length] = '\0';

    return result;
}

static inline bool cy_is_numeric(const char *value)
{
    if (value == NULL || *value == '\0') {
        return false;
    }

    char *end = NULL;
    double parsed = strtod(value, &end);

    if (end == value) {
        return false;
    }

    while (*end == ' ' || *end == '\t' || *end == '\n' || *end == '\r') {
        end++;
    }

    (void) parsed;

    return *end == '\0';
}

static inline bool cy_is_integer(const char *value)
{
    if (value == NULL || *value == '\0') {
        return false;
    }

    const char *cursor = value;

    if (*cursor == '+' || *cursor == '-') {
        cursor++;
    }

    if (*cursor == '\0') {
        return false;
    }

    while (*cursor >= '0' && *cursor <= '9') {
        cursor++;
    }

    return *cursor == '\0';
}

static inline void cy_cli_error(const char *message)
{
    if (message != NULL) {
        fputs(message, stderr);
    }
}

static inline CyArray *cy_cli_args(void)
{
    CyArray *arguments = cy_array_new();

    for (int i = 0; i < cy_cli_argc; i++) {
        cy_array_push_string(arguments, cy_strdup(cy_cli_argv[i]));
    }

    return arguments;
}

/* ------------------------------------------------------------------------- */
/* JSON                                                                      */
/* ------------------------------------------------------------------------- */

typedef struct {
    char *data;
    size_t length;
    size_t capacity;
} CyJsonBuffer;

static inline void cy_json_buffer_init(CyJsonBuffer *buffer)
{
    buffer->data = NULL;
    buffer->length = 0;
    buffer->capacity = 0;
}

static inline void cy_json_buffer_reserve(CyJsonBuffer *buffer, size_t extra)
{
    size_t needed = buffer->length + extra + 1;

    if (needed <= buffer->capacity) {
        return;
    }

    size_t capacity = buffer->capacity == 0 ? 64 : buffer->capacity;

    while (capacity < needed) {
        capacity *= 2;
    }

    char *data = (char *) realloc(buffer->data, capacity);

    if (data == NULL) {
        cy_runtime_out_of_memory();
    }

    buffer->data = data;
    buffer->capacity = capacity;
}

static inline void cy_json_buffer_append(CyJsonBuffer *buffer, const char *data, size_t length)
{
    cy_json_buffer_reserve(buffer, length);
    memcpy(buffer->data + buffer->length, data, length);
    buffer->length += length;
    buffer->data[buffer->length] = '\0';
}

static inline void cy_json_buffer_putc(CyJsonBuffer *buffer, char value)
{
    cy_json_buffer_append(buffer, &value, 1);
}

static inline const char *cy_json_buffer_finish(CyJsonBuffer *buffer)
{
    const char *result = cy_strdup(buffer->data == NULL ? "" : buffer->data);
    free(buffer->data);

    return result;
}

static inline void cy_json_encode_string(CyJsonBuffer *buffer, const char *value)
{
    if (value == NULL) {
        cy_json_buffer_append(buffer, "null", 4);
        return;
    }

    cy_json_buffer_putc(buffer, '"');

    for (const unsigned char *cursor = (const unsigned char *) value; *cursor != 0; cursor++) {
        unsigned char character = *cursor;

        switch (character) {
            case '"':
                cy_json_buffer_append(buffer, "\\\"", 2);
                break;
            case '\\':
                cy_json_buffer_append(buffer, "\\\\", 2);
                break;
            case '\b':
                cy_json_buffer_append(buffer, "\\b", 2);
                break;
            case '\f':
                cy_json_buffer_append(buffer, "\\f", 2);
                break;
            case '\n':
                cy_json_buffer_append(buffer, "\\n", 2);
                break;
            case '\r':
                cy_json_buffer_append(buffer, "\\r", 2);
                break;
            case '\t':
                cy_json_buffer_append(buffer, "\\t", 2);
                break;
            default:
                if (character < 0x20) {
                    char escaped[7];
                    snprintf(escaped, sizeof escaped, "\\u%04x", character);
                    cy_json_buffer_append(buffer, escaped, 6);
                } else {
                    cy_json_buffer_putc(buffer, (char) character);
                }
                break;
        }
    }

    cy_json_buffer_putc(buffer, '"');
}

static inline void cy_json_encode_value(CyJsonBuffer *buffer, CyValue value)
{
    char number[64];

    switch (value.type) {
        case CY_VALUE_NULL:
            cy_json_buffer_append(buffer, "null", 4);
            break;
        case CY_VALUE_BOOL:
            cy_json_buffer_append(
                buffer,
                value.as.boolean ? "true" : "false",
                value.as.boolean ? 4 : 5
            );
            break;
        case CY_VALUE_INT:
            snprintf(number, sizeof number, "%lld", value.as.integer);
            cy_json_buffer_append(buffer, number, strlen(number));
            break;
        case CY_VALUE_FLOAT:
            if (!isfinite(value.as.floating)) {
                cy_throw_exception("nombre JSON non fini");
                cy_json_buffer_append(buffer, "null", 4);
                break;
            }
            snprintf(number, sizeof number, "%.14g", value.as.floating);
            cy_json_buffer_append(buffer, number, strlen(number));
            break;
        case CY_VALUE_STRING:
            cy_json_encode_string(buffer, value.as.string);
            break;
        case CY_VALUE_ARRAY: {
            cy_json_buffer_putc(buffer, '[');

            for (size_t i = 0; i < value.as.array->length; i++) {
                if (i > 0) {
                    cy_json_buffer_putc(buffer, ',');
                }

                cy_json_encode_value(buffer, value.as.array->items[i]);
            }

            cy_json_buffer_putc(buffer, ']');
            break;
        }
        case CY_VALUE_MAP: {
            cy_json_buffer_putc(buffer, '{');

            for (size_t i = 0; i < value.as.map->length; i++) {
                if (i > 0) {
                    cy_json_buffer_putc(buffer, ',');
                }

                cy_json_encode_string(buffer, value.as.map->entries[i].key.as.string);
                cy_json_buffer_putc(buffer, ':');
                cy_json_encode_value(buffer, value.as.map->entries[i].value);
            }

            cy_json_buffer_putc(buffer, '}');
            break;
        }
        default:
            cy_throw_exception("valeur non encodable en JSON");
            cy_json_buffer_append(buffer, "null", 4);
            break;
    }
}

static inline const char *cy_json_encode_int(cy_int value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_value(&buffer, cy_value_int(value));

    return cy_json_buffer_finish(&buffer);
}

static inline const char *cy_json_encode_float(cy_float value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_value(&buffer, cy_value_float(value));

    return cy_json_buffer_finish(&buffer);
}

static inline const char *cy_json_encode_bool(bool value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_value(&buffer, cy_value_bool(value));

    return cy_json_buffer_finish(&buffer);
}

static inline const char *cy_json_encode_string_value(const char *value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_string(&buffer, value);

    return cy_json_buffer_finish(&buffer);
}

static inline const char *cy_json_encode_array(CyArray *value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_value(&buffer, cy_value_array(value));

    return cy_json_buffer_finish(&buffer);
}

static inline const char *cy_json_encode_map(CyMap *value)
{
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);
    cy_json_encode_value(&buffer, cy_value_map(value));

    return cy_json_buffer_finish(&buffer);
}

static inline void cy_json_skip_whitespace(const char **cursor)
{
    while (**cursor == ' ' || **cursor == '\t' || **cursor == '\n' || **cursor == '\r') {
        (*cursor)++;
    }
}

static inline int cy_json_hex(unsigned char character)
{
    if (character >= '0' && character <= '9') {
        return character - '0';
    }

    if (character >= 'a' && character <= 'f') {
        return character - 'a' + 10;
    }

    if (character >= 'A' && character <= 'F') {
        return character - 'A' + 10;
    }

    return -1;
}

static inline unsigned int cy_json_read_hex4(const char **cursor)
{
    unsigned int value = 0;

    for (int i = 0; i < 4; i++) {
        int digit = cy_json_hex((unsigned char) **cursor);

        if (digit < 0) {
            return 0xFFFFFFFFu;
        }

        value = value * 16 + (unsigned int) digit;
        (*cursor)++;
    }

    return value;
}

static inline void cy_json_append_utf8(CyJsonBuffer *buffer, unsigned int code)
{
    char encoded[4];

    if (code <= 0x7F) {
        encoded[0] = (char) code;
        cy_json_buffer_append(buffer, encoded, 1);
    } else if (code <= 0x7FF) {
        encoded[0] = (char) (0xC0 | (code >> 6));
        encoded[1] = (char) (0x80 | (code & 0x3F));
        cy_json_buffer_append(buffer, encoded, 2);
    } else if (code <= 0xFFFF) {
        encoded[0] = (char) (0xE0 | (code >> 12));
        encoded[1] = (char) (0x80 | ((code >> 6) & 0x3F));
        encoded[2] = (char) (0x80 | (code & 0x3F));
        cy_json_buffer_append(buffer, encoded, 3);
    } else {
        encoded[0] = (char) (0xF0 | (code >> 18));
        encoded[1] = (char) (0x80 | ((code >> 12) & 0x3F));
        encoded[2] = (char) (0x80 | ((code >> 6) & 0x3F));
        encoded[3] = (char) (0x80 | (code & 0x3F));
        cy_json_buffer_append(buffer, encoded, 4);
    }
}

static inline const char *cy_json_parse_string(const char **cursor)
{
    if (**cursor != '"') {
        cy_throw_exception("chaîne JSON attendue");
        return "";
    }

    (*cursor)++;
    CyJsonBuffer buffer;
    cy_json_buffer_init(&buffer);

    while (**cursor != 0 && **cursor != '"') {
        unsigned char character = (unsigned char) **cursor;

        if (character != '\\') {
            cy_json_buffer_putc(&buffer, (char) character);
            (*cursor)++;
            continue;
        }

        (*cursor)++;
        unsigned char escaped = (unsigned char) **cursor;

        switch (escaped) {
            case '"':
            case '\\':
            case '/':
                cy_json_buffer_putc(&buffer, (char) escaped);
                (*cursor)++;
                break;
            case 'b':
                cy_json_buffer_putc(&buffer, '\b');
                (*cursor)++;
                break;
            case 'f':
                cy_json_buffer_putc(&buffer, '\f');
                (*cursor)++;
                break;
            case 'n':
                cy_json_buffer_putc(&buffer, '\n');
                (*cursor)++;
                break;
            case 'r':
                cy_json_buffer_putc(&buffer, '\r');
                (*cursor)++;
                break;
            case 't':
                cy_json_buffer_putc(&buffer, '\t');
                (*cursor)++;
                break;
            case 'u': {
                (*cursor)++;
                unsigned int code = cy_json_read_hex4(cursor);

                if (code == 0xFFFFFFFFu) {
                    free(buffer.data);
                    cy_throw_exception("échappement Unicode JSON invalide");
                    return "";
                }

                if (code >= 0xD800 && code <= 0xDBFF
                    && (*cursor)[0] == '\\' && (*cursor)[1] == 'u') {
                    const char *next = *cursor + 2;
                    unsigned int low = cy_json_read_hex4(&next);

                    if (low >= 0xDC00 && low <= 0xDFFF) {
                        code = 0x10000 + ((code - 0xD800) << 10) + (low - 0xDC00);
                        *cursor = next;
                    }
                }

                cy_json_append_utf8(&buffer, code);
                break;
            }
            default:
                free(buffer.data);
                cy_throw_exception("échappement JSON invalide");
                return "";
        }
    }

    if (**cursor != '"') {
        free(buffer.data);
        cy_throw_exception("chaîne JSON non terminée");
        return "";
    }

    (*cursor)++;

    return cy_json_buffer_finish(&buffer);
}

static inline bool cy_json_parse_bool(const char **cursor)
{
    if (strncmp(*cursor, "true", 4) == 0) {
        *cursor += 4;
        return true;
    }

    if (strncmp(*cursor, "false", 5) == 0) {
        *cursor += 5;
        return false;
    }

    cy_throw_exception("booléen JSON attendu");

    return false;
}

static inline cy_int cy_json_parse_int(const char **cursor)
{
    char *end = NULL;
    long long value = strtoll(*cursor, &end, 10);

    if (end == *cursor) {
        cy_throw_exception("entier JSON attendu");
        return 0;
    }

    *cursor = end;

    return (cy_int) value;
}

static inline cy_float cy_json_parse_float(const char **cursor)
{
    char *end = NULL;
    double value = strtod(*cursor, &end);

    if (end == *cursor) {
        cy_throw_exception("nombre JSON attendu");
        return 0.0;
    }

    *cursor = end;

    return (cy_float) value;
}

static inline CyValue cy_json_parse_typed(const char **cursor, CyValueType expected)
{
    cy_json_skip_whitespace(cursor);

    switch (expected) {
        case CY_VALUE_STRING:
            return cy_value_string(cy_json_parse_string(cursor));
        case CY_VALUE_BOOL:
            return cy_value_bool(cy_json_parse_bool(cursor));
        case CY_VALUE_INT:
            return cy_value_int(cy_json_parse_int(cursor));
        case CY_VALUE_FLOAT:
            return cy_value_float(cy_json_parse_float(cursor));
        default:
            cy_throw_exception("type JSON non supporté");
            return cy_value_null();
    }
}

static inline CyArray *cy_json_decode_array(const char *json, CyValueType expected)
{
    CyArray *result = cy_array_new();
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);

    if (*cursor != '[') {
        cy_throw_exception("tableau JSON attendu");
        return result;
    }

    cursor++;
    cy_json_skip_whitespace(&cursor);

    if (*cursor == ']') {
        cursor++;
    } else {
        for (;;) {
            CyValue value = cy_json_parse_typed(&cursor, expected);

            if (cy_exception_pending()) {
                return result;
            }

            cy_array_push_value(result, value);
            cy_json_skip_whitespace(&cursor);

            if (*cursor == ',') {
                cursor++;
                continue;
            }

            if (*cursor == ']') {
                cursor++;
                break;
            }

            cy_throw_exception("tableau JSON invalide");
            return result;
        }
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return result;
}

static inline CyMap *cy_json_decode_map(const char *json, CyValueType expected)
{
    CyMap *result = cy_map_new();
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);

    if (*cursor != '{') {
        cy_throw_exception("objet JSON attendu");
        return result;
    }

    cursor++;
    cy_json_skip_whitespace(&cursor);

    if (*cursor == '}') {
        cursor++;
    } else {
        for (;;) {
            cy_json_skip_whitespace(&cursor);
            const char *key = cy_json_parse_string(&cursor);

            if (cy_exception_pending()) {
                return result;
            }

            cy_json_skip_whitespace(&cursor);

            if (*cursor != ':') {
                cy_throw_exception("objet JSON invalide");
                return result;
            }

            cursor++;
            CyValue value = cy_json_parse_typed(&cursor, expected);

            if (cy_exception_pending()) {
                return result;
            }

            cy_map_set_value_string(result, key, value);
            cy_json_skip_whitespace(&cursor);

            if (*cursor == ',') {
                cursor++;
                continue;
            }

            if (*cursor == '}') {
                cursor++;
                break;
            }

            cy_throw_exception("objet JSON invalide");
            return result;
        }
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return result;
}

static inline cy_int fn_json_decode_int(const char *json)
{
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);
    cy_int value = cy_json_parse_int(&cursor);

    if (cy_exception_pending()) {
        return value;
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return value;
}

static inline cy_float fn_json_decode_float(const char *json)
{
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);
    cy_float value = cy_json_parse_float(&cursor);

    if (cy_exception_pending()) {
        return value;
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return value;
}

static inline bool fn_json_decode_bool(const char *json)
{
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);
    bool value = cy_json_parse_bool(&cursor);

    if (cy_exception_pending()) {
        return value;
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return value;
}

static inline const char *fn_json_decode_string(const char *json)
{
    const char *cursor = json;
    cy_json_skip_whitespace(&cursor);
    const char *value = cy_json_parse_string(&cursor);

    if (cy_exception_pending()) {
        return value;
    }

    cy_json_skip_whitespace(&cursor);

    if (*cursor != 0) {
        cy_throw_exception("contenu JSON superflu");
    }

    return value;
}

static inline CyArray *fn_json_decode_array_int(const char *json)
{
    return cy_json_decode_array(json, CY_VALUE_INT);
}

static inline CyArray *fn_json_decode_array_float(const char *json)
{
    return cy_json_decode_array(json, CY_VALUE_FLOAT);
}

static inline CyArray *fn_json_decode_array_bool(const char *json)
{
    return cy_json_decode_array(json, CY_VALUE_BOOL);
}

static inline CyArray *fn_json_decode_array_string(const char *json)
{
    return cy_json_decode_array(json, CY_VALUE_STRING);
}

static inline CyMap *fn_json_decode_map_int(const char *json)
{
    return cy_json_decode_map(json, CY_VALUE_INT);
}

static inline CyMap *fn_json_decode_map_float(const char *json)
{
    return cy_json_decode_map(json, CY_VALUE_FLOAT);
}

static inline CyMap *fn_json_decode_map_bool(const char *json)
{
    return cy_json_decode_map(json, CY_VALUE_BOOL);
}

static inline CyMap *fn_json_decode_map_string(const char *json)
{
    return cy_json_decode_map(json, CY_VALUE_STRING);
}

#endif
