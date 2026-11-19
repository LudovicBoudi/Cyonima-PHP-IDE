<?php
declare(strict_types=1);
$gi = FFI::cdef(<<<'C'
void       *g_irepository_get_default(void);
void       *g_irepository_require(void *repo, const char *ns, const char *version, int flags, void **error);
int         g_irepository_get_n_infos(void *repo, const char *ns);
void       *g_irepository_get_info(void *repo, const char *ns, int index);
const char *g_base_info_get_name(void *info);
const char *g_base_info_get_namespace(void *info);
int         g_base_info_get_type(void *info);
C, 'libgirepository-1.0.so.1');

$repo = $gi->g_irepository_get_default();
$err = FFI::new('void*[1]', false);
$err[0] = null;

foreach ([['Gtk','4.0'], ['Adw','1'], ['GtkSource','5'], ['Vte','3.91'], ['GObject','2.0']] as [$ns, $ver]) {
    $tl = $gi->g_irepository_require($repo, $ns, $ver, 0, $err);
    if ($tl === null) {
        printf("%-10s %-5s ERREUR\n", $ns, $ver);
        continue;
    }
    $n = $gi->g_irepository_get_n_infos($repo, $ns);
    $sample = [];
    for ($i = 0; $i < min(3, $n); $i++) {
        $sample[] = (string) $gi->g_base_info_get_name($gi->g_irepository_get_info($repo, $ns, $i));
    }
    printf("%-10s %-5s %5d types  (ex: %s)\n", $ns, $ver, $n, implode(', ', $sample));
}
