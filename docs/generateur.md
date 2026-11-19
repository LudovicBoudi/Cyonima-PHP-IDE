# Générateur de bindings

> État : tranche verticale M1 livrée (7 classes, 54 méthodes, 2 signaux, 2 enums).
> Cible suivante : étendre la spec à Adw, GtkSourceView et VTE.

## Principe

Les bindings ne sont pas écrits à la main : ils sont **générés depuis les
`.typelib`** de GObject Introspection, qui décrivent types, signatures,
ownership et signaux.

Pourquoi les `.typelib` et pas les `.gir` XML :

- ils sont présents dans le runtime GNOME et dans les Flatpaks, sans paquet
  `-dev` ;
- ils sont la forme compilée de la même information ;
- ils sont lisibles depuis PHP via `libgirepository` (`src/Introspection/Gi.php`).

Le générateur vérifie chaque méthode déclarée dans la spec contre le typelib :
une API qui bouge fait **échouer la génération** au lieu de produire du code
silencieusement faux.

## Pipeline

```
spec/*.php
   │  sélection : classes, méthodes, noms de paramètres, enums
   ▼
Reader (src/Introspection)      ── lit les .typelib
   ▼
Generator (src/Generator)
   ├── TypeMapper      types C ↔ PHP, ownership
   ├── ClassEmitter    classes Raw
   └── SymbolsEmitter  cdef unique + bootstrap GTK
   ▼
src/Gtk/Raw/*.php  (généré, ne pas éditer)
   ▼
src/UI/*.php       (façade écrite à la main)
```

## Commandes

```bash
# Génère les bindings depuis la spec par défaut
php -d ffi.enable=true bin/cyonima-gen generate

# Inspecte un namespace ou un type issu du typelib
php -d ffi.enable=true bin/cyonima-gen inspect Gtk 4.0
php -d ffi.enable=true bin/cyonima-gen inspect Gtk 4.0 Window
php -d ffi.enable=true bin/cyonima-gen functions Gtk 4.0

# Tests
php -d ffi.enable=true tests/run.php

# Démo (fenêtre réelle ; --auto pour un scénario scripté)
php -d ffi.enable=true examples/counter.php
php -d ffi.enable=true examples/counter.php --auto
```

## Format de spec

`spec/gtk-slice.php` :

```php
return [
    'output' => 'src',

    'namespaces' => [
        'Gtk' => [
            'version' => '4.0',
            'library' => 'libgtk-4.so.1',
            'raw_namespace' => 'Cyonima\\Gtk\\Raw',
        ],
    ],

    'classes' => [
        'Gtk\\Window' => [
            'methods' => [
                'new' => [],
                'set_title' => ['title'],
                'set_default_size' => ['width', 'height'],
            ],
            'signals' => ['close-request'], // true = tous
        ],
    ],

    'enums' => [
        'Gtk\\Orientation' => true,
    ],
];
```

Chaque entrée `method => [noms des paramètres]` est **vérifiée** : nom présent
dans le typelib et nombre de paramètres exact. Les noms servent à produire une
API lisible, car le typelib ne les stocke pas.

## Conventions de nommage

| Typelib | PHP généré |
|---|---|
| `set_default_size` | `setDefaultSize()` |
| `new_with_label` | `newWithLabel()` |
| signal `close-request` | `SIGNAL_CLOSE_REQUEST` + `onCloseRequest()` |
| enum `GTK_ORIENTATION_VERTICAL` | `Orientation::VERTICAL` |

## Gestion mémoire

- Chaque constructeur retourne une référence flottante : `RawObject::adopt()`
  appelle `g_object_ref_sink()`, le destructeur appelle `g_object_unref()`.
- Les getters retournant un objet produisent un wrapper **non possessif**
  (`wrap($ptr, false)`) : l'objet appartient au parent.
- Un widget parenté survit à la libération de son wrapper PHP.

## Limites assumées de la tranche

| Cas | Traitement |
|---|---|
| Paramètres `out` / `inout` | méthode ignorée (comptée dans le rapport) |
| Tableaux, `GList`, `GHashTable` | méthode ignorée |
| Structs passés par valeur | méthode ignorée |
| `GError` | méthode ignorée |
| Interfaces non générées (`GtkStyleProvider`, `GdkDisplay`…) | type opaque `\FFI\CData` |
| Objet retourné par un getter | typé selon la signature déclarée, pas le type dynamique |
| `GObject.Object` | non généré : PHP réserve le nom de classe `Object`, `RawObject` sert de racine |

Le rapport de génération liste explicitement chaque méthode ignorée et sa
raison ; rien n'est écarté en silence.

## Tests

`tests/run.php` couvre :

1. présence et exactitude des artefacts générés (noms de types, enums, signaux) ;
2. **déterminisme** : deux générations produisent des fichiers identiques ;
3. cycle de vie : 2 000 widgets créés/libérés, delta RSS hors warm-up ;
4. signaux : handlers PHP appelés, exceptions capturées (règle R2) ;
5. boucle d'événements GTK réelle, fermeture par `close-request` ;
6. **différentiel PyGObject** : enums, signaux et méthodes comparés à
   l'implémentation de référence.

Le différentiel PyGObject est le garde-fou contre une lecture erronée du
typelib : si notre interprétation diverge de la référence, le test casse.

## Étendre la surface

1. Ajouter la classe / la méthode dans `spec/gtk-slice.php`.
2. Lancer la génération : une méthode absente ou un nombre de paramètres
   incorrect échoue immédiatement.
3. Ajouter si besoin les helpers correspondants dans `src/UI/`.
4. Lancer les tests.
