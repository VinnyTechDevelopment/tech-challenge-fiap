<?php

namespace App\Domain\Customer\Entities;

use Illuminate\Support\Str;

class Customer
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $phone,
        public string $document,
        public string $status = 'active'
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function create(
        string $name,
        string $email,
        string $phone,
        string $document
    ): self {
        return new self(
            Str::uuid()->toString(),
            $name,
            $email,
            $phone,
            $document
        );
    }

    public function updateData(
        ?string $name,
        ?string $email,
        ?string $phone,
        ?string $document
    ): void {
        if ($name !== null) {
            $this->name = $name;
        }
        if ($email !== null) {
            $this->email = $email;
        }
        if ($phone !== null) {
            $this->phone = $phone;
        }
        if ($document !== null) {
            $this->document = $document;
        }
    }
}
