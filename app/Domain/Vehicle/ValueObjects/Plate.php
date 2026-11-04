<?php

namespace App\Domain\Vehicle\ValueObjects;

use InvalidArgumentException;

class Plate
{
    private string $value;

    public function __construct(string $plate)
    {
        if (! preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $plate)) {
            throw new InvalidArgumentException('Placa inválida');
        }

        $this->value = strtoupper($plate);
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
