<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Un domaine que seul un rôle voit : la facturation transverse au seul
 * super-administrateur, le contenu du site aux managers… (swan, 2026-10-25).
 *
 * ⚠ **Pour qui n'a pas le rôle, ses outils N'EXISTENT PAS** : ni dans
 *   `tools/list`, ni dans les instructions, et les appeler répond « outil
 *   inconnu », comme un nom qui n'existe nulle part. Un refus lisible
 *   apprendrait à l'agent ce qui est fermé.
 *
 * ⚠ **Le défaut ferme** : sans vérificateur de droits dans le conteneur, un
 *   domaine réservé n'est visible de personne.
 *
 * Ce n'est PAS le lieu des droits sur un objet (ce projet, cette entreprise) :
 * le domaine les vérifie lui-même, avec les mêmes règles que les écrans.
 */
interface DomaineReserve extends DomaineMcp
{
    /** Le rôle (ou l'attribut de voter) qu'il faut pour voir et appeler ces outils. */
    public function role(): string;

    /**
     * Ce que l'agent doit savoir de ces outils, ajouté aux instructions du
     * serveur pour qui les voit seulement ; chaîne vide pour rien.
     */
    public function consignes(): string;
}
