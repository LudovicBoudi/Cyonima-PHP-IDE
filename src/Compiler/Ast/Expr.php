<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

abstract class Expr extends Node
{
    public ?string $type = null;
}
