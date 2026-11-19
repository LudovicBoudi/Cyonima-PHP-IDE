<?php
declare(strict_types=1);
function rssMo(): float { return ((int) explode(' ', file_get_contents('/proc/self/statm'))[1]) * 4096 / 1048576; }
$ffi = FFI::cdef(<<<'C'
unsigned int g_timeout_add(unsigned int interval, int (*handler)(void *), void *data);
unsigned int g_idle_add(int (*handler)(void *), void *data);
int g_source_remove(unsigned int tag);
C, 'libglib-2.0.so.0');

$mode = $argv[1]; $n = (int)$argv[2];
$cb = function ($d): int { return 0; }; // G_SOURCE_REMOVE

$before = rssMo();
for ($i = 0; $i < $n; $i++) {
    if ($mode === 'remove') {
        $tag = $ffi->g_idle_add($cb, null);
        $ffi->g_source_remove($tag);
        continue;
    }
    $mode === 'timeout'
        ? $ffi->g_timeout_add(1000, $cb, null)
        : $ffi->g_idle_add($cb, null);
}
printf("%-8s %6d enregistrements : RSS %.1f -> %.1f Mo (delta %+.2f Mo, %.0f o/enreg.)\n",
    $mode, $n, $before, rssMo(), rssMo()-$before, (rssMo()-$before)*1048576/$n);
