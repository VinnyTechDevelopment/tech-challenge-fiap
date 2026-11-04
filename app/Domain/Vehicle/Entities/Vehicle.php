<?php

namespace App\Domain\Vehicle\Entities;

use App\Domain\Vehicle\ValueObjects\Plate;
use Illuminate\Support\Str;

class Vehicle
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $model,
        public int $year,
        public Plate $plate
    ) {}

    public static function create(
        string $brand,
        string $model,
        int $year,
        Plate $plate
    ): self {
        return new self(
            Str::uuid()->toString(),
            $brand,
            $model,
            $year,
            $plate
        );
    }

    public function updateData(
        ?string $brand,
        ?string $model,
        ?int $year,
        ?Plate $plate
    ): void {
        if ($brand !== null) {
            $this->brand = $brand;
        }

        if ($model !== null) {
            $this->model = $model;
        }

        if ($year !== null) {
            $this->year = $year;
        }

        if ($plate !== null) {
            $this->plate = $plate;
        }
    }
}
