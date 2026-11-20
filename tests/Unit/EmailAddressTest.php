<?php

declare(strict_types=1);

use FlowBalance\Identity\Domain\EmailAddress;
use FlowBalance\Identity\Domain\RegistrationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The criterion used to be lower(btrim(x, <space, tab, LF, CR, FF, VT>)) inside PostgreSQL. The expected
 * values below were checked against that function when the engine was replaced.
 */
final class EmailAddressTest extends TestCase
{
    #[DataProvider('equivalentToTheFormerSqlFunction')]
    public function testNormalizesLikeTheFormerSqlFunction(string $input, string $expected): void
    {
        self::assertSame($expected, EmailAddress::normalize($input));
    }

    public static function equivalentToTheFormerSqlFunction(): iterable
    {
        yield 'capitals' => ['Ana@Example.COM', 'ana@example.com'];
        yield 'every trimmed character on both ends' => [" \t\n\r\v\f ana@x.com \t", 'ana@x.com'];
        yield 'inner spaces are kept' => ['a b@x.com', 'a b@x.com'];
        yield 'a non-breaking space is not trimmed' => ["\u{00A0}ana@x.com\u{00A0}", "\u{00A0}ana@x.com\u{00A0}"];
        yield 'an em space is not trimmed' => ["ana@x.com\u{2003}", "ana@x.com\u{2003}"];
        yield 'already normalized' => ['ana@x.com', 'ana@x.com'];
        yield 'accents are kept, only case folds' => ['ÉCOLE@x.fr', 'école@x.fr'];
    }

    public function testTwoSpellingsOfOneAddressAreTheSameAccount(): void
    {
        self::assertSame(EmailAddress::normalize('Ana@Example.com'), EmailAddress::normalize("  ana@EXAMPLE.COM\n"));
        self::assertNotSame(EmailAddress::normalize('ana@example.com'), EmailAddress::normalize('ana2@example.com'));
    }

    /**
     * Where PHP and the former SQL function disagree (Turkish dotted İ, a final sigma, an embedded NUL).
     * None can reach the normalizer while the entry validation refuses non-ASCII addresses; if it ever admits
     * them, this test fails so the difference is decided on purpose.
     */
    #[DataProvider('whereTheFormerFunctionDiffered')]
    public function testInputsWhereTheEnginesDifferNeverPassValidation(string $address): void
    {
        self::assertFalse(RegistrationInput::validEmail($address));
    }

    public static function whereTheFormerFunctionDiffered(): iterable
    {
        yield 'dotted capital I' => ['İSTANBUL@x.com'];
        yield 'capital sigma at the end of a word' => ['ΣΊΣΥΦΟΣ@x.gr'];
        yield 'a short Greek word ending in sigma' => ['ΑΣ@x.gr'];
        yield 'a NUL byte' => ["\0ana@x.com"];
    }
}
