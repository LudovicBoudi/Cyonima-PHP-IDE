<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

/**
 * Module natif lié au binaire compilé.
 *
 * Un module fournit :
 *  - la signature PHP de ses fonctions ;
 *  - les includes C nécessaires ;
 *  - les définitions C des fonctions `fn_<nom>` ;
 *  - le nom pkg-config ou les options d’édition de liens.
 */
interface NativeModule
{
    public function name(): string;

    /** @return array<string, NativeFunction> */
    public function functions(): array;

    /** @return list<string> */
    public function includes(): array;

    public function source(): string;

    public function pkgConfig(): ?string;

    public function linkFlags(): string;

    /** @return list<string> bibliothèques à embarquer dans un Flatpak */
    public function flatpakLibraries(): array;
}
