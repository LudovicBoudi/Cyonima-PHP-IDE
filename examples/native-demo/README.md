# Cyonima Demo

Application de démonstration complète, compilée en binaire natif GTK4.

## Fonctionnalités

- template `.ui` principal avec fragments de widgets préconstruits ;
- SQLite en mémoire et migrations ;
- entités et repositories ORM ;
- relation `belongsTo` entre tâches et utilisateur ;
- validation typée du titre ;
- menu natif, accélérateur `Ctrl+Q` ;
- `ListView` alimentée par un `StringList` ;
- mise à jour du statut via `Label` ;
- packaging autonome possible via `--package=appimage` ou `--package=flatpak`.

## Compilation

Depuis la racine du dépôt :

```bash
bin/cyonima-compile \
  --project=examples/native-demo \
  --php=examples/native-demo/app.php \
  --emit=elf
```

Puis :

```bash
./examples/native-demo/dist/app
```

Ou directement un paquet :

```bash
bin/cyonima-compile \
  --project=examples/native-demo \
  --php=examples/native-demo/app.php \
  --package=appimage
```

## Utilisation

- saisir une tâche dans le champ ;
- valider avec Entrée ou le bouton « Ajouter » ;
- cliquer une ligne pour afficher son propriétaire ;
- `Ctrl+Q` ou menu Fichier → Quitter.

## Structure

```text
examples/native-demo/
├── app.php
├── README.md
└── templates/
    └── demo-page.ui
```
