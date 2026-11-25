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

static const char *cy_current_exception = NULL;
static bool cy_exception_pending_flag = false;

static inline void cy_throw_exception(const char *message)
{
    cy_current_exception = message == NULL ? "" : message;
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

static inline const char *cy_exception_capture(void)
{
    const char *message = cy_exception_message();
    cy_current_exception = NULL;
    cy_exception_pending_flag = false;

    return message;
}

typedef enum {
    CY_VALUE_NULL,
    CY_VALUE_BOOL,
    CY_VALUE_INT,
    CY_VALUE_FLOAT,
    CY_VALUE_STRING,
    CY_VALUE_ARRAY,
    CY_VALUE_MAP
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

static inline void cy_gc_collect(void)
{
    cy_mark_pointer((void *) cy_current_exception);

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

static inline CyValue cy_value_map(CyMap *value)
{
    CyValue result;
    result.type = CY_VALUE_MAP;
    result.as.map = value;

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

static inline const char *cy_strdup(const char *value)
{
    size_t length = strlen(value);
    char *copy = (char *) cy_alloc_kind(length + 1, CY_ALLOC_STRING);
    memcpy(copy, value, length + 1);

    return copy;
}

static inline const char *cy_string_concat(const char *left, const char *right)
{
    size_t leftLength = strlen(left);
    size_t rightLength = strlen(right);
    char *result = (char *) cy_alloc_kind(leftLength + rightLength + 1, CY_ALLOC_STRING);
    memcpy(result, left, leftLength);
    memcpy(result + leftLength, right, rightLength + 1);

    return result;
}

static inline const char *cy_string_from_string(const char *value)
{
    return value;
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

#endif
