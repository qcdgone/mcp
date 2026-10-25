<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Journal;

/**
 * Les arguments d'un appel, débarrassés de ce qui ne doit JAMAIS être
 * journalisé : jeton de confirmation, jetons OAuth, secrets, mots de passe.
 *
 * ⚠ Par le NOM de la clé, à toute profondeur : un `confirm_token` glissé dans
 *   un objet imbriqué est masqué comme les autres. On masque la valeur au lieu
 *   de retirer la clé : le journal doit dire qu'un jeton a été présenté.
 */
final class ParametresSansSecret
{
    public const string MASQUE = '***';

    /**
     * ⚠ Avec les secrets, les données qui désignent une PERSONNE :
     *   identifiants de clic, adresses, téléphones, empreintes de ceux-ci.
     */
    private const string MOTIF = '/(token|secret|password|mot_de_passe|authorization|gclid|gbraid|wbraid|email|phone|hashed)/i';

    /**
     * @param array<array-key, mixed> $parametres
     *
     * @return array<array-key, mixed>
     */
    public static function de(array $parametres): array
    {
        foreach ($parametres as $cle => $valeur) {
            if (\is_string($cle) && 1 === preg_match(self::MOTIF, $cle)) {
                $parametres[$cle] = self::MASQUE;
            } elseif (\is_array($valeur)) {
                $parametres[$cle] = self::de($valeur);
            }
        }

        return $parametres;
    }
}
