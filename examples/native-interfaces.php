<?php

declare(strict_types=1);

/**
 * Exemple d’interfaces, d’héritage d’interfaces et de classes abstraites.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-interfaces.php --emit=elf
 *   ./dist/native-interfaces
 */

interface Speaker
{
    public function speak(): string;
}

interface Named extends Speaker
{
    public function name(): string;
}

abstract class Animal implements Named
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }
}

class Dog extends Animal
{
    public function speak(): string
    {
        return 'Woof';
    }
}

interface Factory
{
    public function create(): Animal;
}

class DogFactory implements Factory
{
    public function create(): Dog
    {
        return new Dog('Rex');
    }
}

function describe(Speaker $speaker): string
{
    return $speaker->speak();
}

function identify(Named $named): string
{
    return $named->name();
}

function create(Factory $factory): string
{
    return $factory->create()->name();
}

$dog = new Dog('Rex');
echo identify($dog), ': ', describe($dog), "\n";
echo create(new DogFactory()), "\n";
