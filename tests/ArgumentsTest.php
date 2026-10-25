<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\ArgumentsInvalides;
use Qcdgone\Mcp\Journal\IssueDAppel;

final class ArgumentsTest extends TestCase
{
    /** ⚠ Absent ≠ `null` : un champ absent n'est pas touché, un champ à `null` est vidé. */
    public function testUnChampAbsentNEstPasUnChampVide(): void
    {
        $arguments = new Arguments(['note' => null]);
        $touches = [];
        $arguments->si('note', static function () use (&$touches): void { $touches[] = 'note'; });
        $arguments->si('titre', static function () use (&$touches): void { $touches[] = 'titre'; });

        self::assertSame(['note'], $touches);
    }

    public function testUnEntierSeLitDeSesFormesEntieresSeulement(): void
    {
        $arguments = new Arguments(['a' => 3, 'b' => 4.0, 'c' => '12']);

        self::assertSame([3, 4, 12, 9], [$arguments->entier('a'), $arguments->entier('b'), $arguments->entier('c'), $arguments->entier('d', 9)]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonEntiers(): iterable
    {
        yield 'décimal' => [4.5];
        yield 'texte' => ['douze'];
        yield 'négatif en texte' => ['-3'];
        yield 'booléen' => [true];
    }

    #[DataProvider('nonEntiers')]
    public function testCeQuiNEstPasUnEntierEstRefuse(mixed $valeur): void
    {
        $this->expectException(ArgumentsInvalides::class);

        (new Arguments(['n' => $valeur]))->entier('n');
    }

    public function testUnMontantEnEurosSeLitEnCentimes(): void
    {
        self::assertSame(1999, (new Arguments(['prix' => 19.99]))->centimes('prix'));
    }

    public function testUnTexteFaitDEspacesVautAbsent(): void
    {
        self::assertNull((new Arguments(['t' => '   ']))->texte('t'));
        self::assertSame('ok', (new Arguments(['t' => ' ok ']))->texte('t'));
    }

    public function testUneValeurHorsEnumerationDitLesValeursAttendues(): void
    {
        $this->expectException(ArgumentsInvalides::class);
        $this->expectExceptionMessage('issue : valeur attendue parmi ok, refused, error.');

        (new Arguments(['issue' => 'peut-etre']))->enum('issue', IssueDAppel::class);
    }

    public function testUnObjetNEstPasUneListe(): void
    {
        $this->expectException(ArgumentsInvalides::class);

        (new Arguments(['o' => [1, 2]]))->objet('o');
    }
}
