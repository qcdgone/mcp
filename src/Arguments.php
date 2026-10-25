<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Les arguments d'un appel d'outil, lus et typés.
 *
 * ⚠ **Absent ≠ `null`** pour une mise à jour : un champ ABSENT n'est pas
 *   touché, un champ à `null` est vidé. D'où {@see si()}.
 *
 * Un argument du mauvais type lève {@see ArgumentsInvalides} (erreur -32602) :
 * l'agent s'est trompé d'argument, il doit le savoir.
 */
final readonly class Arguments
{
    /** @param array<string, mixed> $valeurs */
    public function __construct(private array $valeurs)
    {
    }

    /** @return array<string, mixed> tous les arguments, tels que reçus — pour une empreinte, un journal */
    public function tous(): array
    {
        return $this->valeurs;
    }

    public function a(string $cle): bool
    {
        return \array_key_exists($cle, $this->valeurs);
    }

    /** Exécute `$faire` seulement si l'argument est fourni (même à `null`). */
    public function si(string $cle, callable $faire): void
    {
        if ($this->a($cle)) {
            $faire();
        }
    }

    public function brut(string $cle): mixed
    {
        return $this->valeurs[$cle] ?? null;
    }

    public function entier(string $cle, ?int $defaut = null): ?int
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return $defaut;
        }
        if (\is_int($v) || (\is_float($v) && floor($v) === $v) || (\is_string($v) && ctype_digit($v))) {
            return (int) $v;
        }

        throw new ArgumentsInvalides(\sprintf('%s : un entier est attendu.', $cle));
    }

    public function entierObligatoire(string $cle): int
    {
        return $this->entier($cle) ?? throw new ArgumentsInvalides(\sprintf('%s : obligatoire.', $cle));
    }

    public function nombre(string $cle): ?float
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        throw new ArgumentsInvalides(\sprintf('%s : un nombre est attendu.', $cle));
    }

    /** Un montant en euros, rendu en CENTIMES (la base ne connaît que des centimes). */
    public function centimes(string $cle): ?int
    {
        $euros = $this->nombre($cle);

        return null === $euros ? null : (int) round($euros * 100);
    }

    /** Une chaîne, `null` si absente, vide ou faite d'espaces. */
    public function texte(string $cle): ?string
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return null;
        }
        if (!\is_string($v)) {
            throw new ArgumentsInvalides(\sprintf('%s : une chaîne est attendue.', $cle));
        }

        return '' === trim($v) ? null : trim($v);
    }

    public function texteObligatoire(string $cle): string
    {
        return $this->texte($cle) ?? throw new ArgumentsInvalides(\sprintf('%s : obligatoire.', $cle));
    }

    public function booleen(string $cle, ?bool $defaut = null): ?bool
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return $defaut;
        }
        if (!\is_bool($v)) {
            throw new ArgumentsInvalides(\sprintf('%s : true ou false est attendu.', $cle));
        }

        return $v;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public function enum(string $cle, string $enum): ?\BackedEnum
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return null;
        }

        return $enum::tryFrom(\is_scalar($v) ? (string) $v : '') ?? throw new ArgumentsInvalides(\sprintf(
            '%s : valeur attendue parmi %s.',
            $cle,
            implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases())),
        ));
    }

    public function date(string $cle): ?\DateTimeImmutable
    {
        $v = $this->texte($cle);
        if (null === $v) {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            throw new ArgumentsInvalides(\sprintf('%s : une date est attendue (AAAA-MM-JJ ou ISO 8601).', $cle));
        }
    }

    /** @return list<mixed>|null */
    public function liste(string $cle): ?array
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return null;
        }
        if (!\is_array($v) || !array_is_list($v)) {
            throw new ArgumentsInvalides(\sprintf('%s : une liste est attendue.', $cle));
        }

        return $v;
    }

    /** @return list<int> */
    public function entiers(string $cle): array
    {
        return array_map(static function (mixed $v) use ($cle): int {
            if (!\is_int($v)) {
                throw new ArgumentsInvalides(\sprintf('%s : une liste d\'identifiants entiers est attendue.', $cle));
            }

            return $v;
        }, $this->liste($cle) ?? []);
    }

    /** @return list<string> */
    public function textes(string $cle): array
    {
        $textes = [];
        foreach ($this->liste($cle) ?? [] as $v) {
            if (!\is_string($v)) {
                throw new ArgumentsInvalides(\sprintf('%s : une liste de chaînes est attendue.', $cle));
            }
            if ('' !== trim($v)) {
                $textes[] = trim($v);
            }
        }

        return $textes;
    }

    /** @return array<string, mixed>|null */
    public function objet(string $cle): ?array
    {
        $v = $this->valeurs[$cle] ?? null;
        if (null === $v) {
            return null;
        }
        if (!\is_array($v) || ([] !== $v && array_is_list($v))) {
            throw new ArgumentsInvalides(\sprintf('%s : un objet est attendu.', $cle));
        }

        return $v;
    }
}
