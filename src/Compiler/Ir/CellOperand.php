<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

/**
 * Référence vers la cellule d’une variable capturée par référence.
 *
 * Le backend C rend l’adresse de la cellule (pointeur), pas sa valeur.
 */
final class CellOperand extends Operand
{
    public function __construct(
        public readonly string $name,
        string $type,
    ) {
        parent::__construct($type);
    }
}
