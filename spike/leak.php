<?php
/**
 * Test de fuite mémoire des callbacks FFI sur un process longue durée.
 * Mesure le RSS avant/après N invocations de closures PHP depuis C.
 */

declare(strict_types=1);

function rssMo(): float
{
    $statm = file_get_contents('/proc/self/statm');
    $pages = (int) explode(' ', $statm)[1];
    return $pages * 4096 / 1048576;
}

$mode = $argv[1] ?? 'qsort';
$n = (int) ($argv[2] ?? 200000);

if ($mode === 'qsort') {
    $ffi = FFI::cdef(
        'typedef int (*cmp_t)(const void*, const void*);
         void qsort(void *base, size_t nmemb, size_t size, cmp_t compar);',
        'libc.so.6'
    );
    $arr = FFI::new('int[2]', false);
    $arr[0] = 2; $arr[1] = 1;
    $cmp = function ($a, $b): int {
        $x = FFI::cast('const int*', $a)[0];
        $y = FFI::cast('const int*', $b)[0];
        return $x <=> $y;
    };
    $before = rssMo();
    for ($i = 0; $i < $n; $i++) {
        $arr[0] = 2; $arr[1] = 1;
        $ffi->qsort($arr, 2, 4, $cmp);
    }
    printf("qsort  : %d callbacks, RSS %.1f -> %.1f Mo (delta %+.2f Mo)\n",
        $n * 1, $before, rssMo(), rssMo() - $before);
    exit;
}

if ($mode === 'gtk') {
    $gtk = FFI::cdef(<<<'C'
int   gtk_init_check(void);
void *gtk_button_new_with_label(const char *label);
unsigned long g_signal_connect_data(void *instance, const char *signal,
    int (*handler)(void *, void *), void *data, void *destroy, int flags);
C, 'libgtk-4.so.1');
    $gobj = FFI::cdef('int g_signal_emit_by_name(void *instance, const char *signal, ...);',
        'libgobject-2.0.so.0');

    if (!$gtk->gtk_init_check()) { fwrite(STDERR, "no display\n"); exit(1); }

    $counter = 0;
    $button = $gtk->gtk_button_new_with_label('x');
    $handler = function ($w, $d) use (&$counter): int { $counter++; return 0; };
    $gtk->g_signal_connect_data($button, 'clicked', $handler, null, null, 0);

    $before = rssMo();
    $checkpoints = [];
    for ($i = 1; $i <= $n; $i++) {
        $gobj->g_signal_emit_by_name($button, 'clicked');
        if ($i % 50000 === 0) {
            $checkpoints[] = sprintf('%dk: %.1f Mo', $i / 1000, rssMo());
        }
    }
    printf("gtk    : %d callbacks, RSS %.1f -> %.1f Mo (delta %+.2f Mo)\n",
        $counter, $before, rssMo(), rssMo() - $before);
    echo '         ', implode(' | ', $checkpoints), "\n";
    exit;
}
