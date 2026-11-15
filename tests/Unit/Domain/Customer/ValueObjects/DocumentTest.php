<?php

namespace Tests\Unit\Domain\Customer\ValueObjects;

use App\Domain\Customer\ValueObjects\Document;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // CPF válido
    // -------------------------------------------------------------------------

    public function test_accepts_valid_cpf(): void
    {
        $doc = new Document('529.982.247-25');

        $this->assertSame('52998224725', $doc->getValue());
    }

    public function test_accepts_cpf_without_mask(): void
    {
        $doc = new Document('52998224725');

        $this->assertSame('52998224725', $doc->getValue());
    }

    // -------------------------------------------------------------------------
    // CPF inválido
    // -------------------------------------------------------------------------

    public function test_rejects_cpf_with_all_same_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('111.111.111-11');
    }

    public function test_rejects_cpf_with_wrong_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('529.982.247-99');
    }

    public function test_rejects_document_with_less_than_11_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('1234567');
    }

    public function test_rejects_document_with_between_11_and_14_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('1234567890123');
    }

    // -------------------------------------------------------------------------
    // CNPJ válido
    // -------------------------------------------------------------------------

    public function test_accepts_valid_cnpj(): void
    {
        $doc = new Document('11.222.333/0001-81');

        $this->assertSame('11222333000181', $doc->getValue());
    }

    public function test_accepts_cnpj_without_mask(): void
    {
        $doc = new Document('11222333000181');

        $this->assertSame('11222333000181', $doc->getValue());
    }

    // -------------------------------------------------------------------------
    // CNPJ inválido
    // -------------------------------------------------------------------------

    public function test_rejects_cnpj_with_all_same_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ inválido.');

        new Document('11.111.111/1111-11');
    }

    public function test_rejects_cnpj_with_wrong_first_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ inválido.');

        // 11222333000181 é válido; trocar dígito 12 (índice 12) de 8 para 7 invalida o 1º dígito verificador
        new Document('11222333000171');
    }

    public function test_rejects_cnpj_with_wrong_second_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ inválido.');

        // 1º dígito verificador (posição 12) correto = 8, 2º (posição 13) alterado de 1 para 0
        new Document('11222333000180');
    }

    public function test_rejects_cpf_exception_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CPF inválido.');

        new Document('529.982.247-99');
    }

    public function test_rejects_document_invalid_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Documento inválido. Deve ser um CPF ou CNPJ válido.');

        new Document('123456789012'); // 12 dígitos — nem CPF nem CNPJ
    }
}
