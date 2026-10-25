# CLAUDE.md

`qcdgone/mcp` — le serveur MCP des applications du parc. Bundle Symfony, publié par
tag git. Le mode d'emploi est dans le `README.md` ; ce fichier dit ce qu'il ne faut
pas casser.

⚠ C'est une **bibliothèque**, pas une application : pas de `vendor/` commité, pas de
kernel, pas d'application de test. `composer.lock` est volontairement ignoré.

⚠ Le code, les commentaires et la documentation sont **en français**, messages de
commit compris. Les textes destinés à l'agent (instructions, descriptions d'outils,
messages d'erreur du protocole) restent dans la langue que l'application a choisie.

## D'où il vient

Le serveur a été écrit pour serelios (SRL-5), puis recopié dans qcdeal, carport
(QSF-71) et mcp-google-ads (MGA-3). Les copies avaient déjà divergé :
- QCDeal n'aliasait pas l'appelant délégué : ses appels MCP échappaient au quota et
  au journal, sans que rien ne le dise ;
- deux façons de déclarer les outils coexistaient ;
- seul google-ads avait des gardes et un journal.

**Ce bundle est le point de départ, et il n'y en aura plus d'autre.** Une correction
de protocole se fait ici, jamais dans une application.

## Ce qui n'entre PAS ici

| Pas ici | Pourquoi |
|---|---|
| L'authentification | `qcdgone/sso` la porte (jeton, JWKS, métadonnées, `WWW-Authenticate`) ; la recopier ferait deux validations du même jeton, qui divergeraient |
| Le pare-feu | l'ordre des pare-feux est arbitré par l'application ; un bundle qui en ajoute un ne le place jamais au bon endroit |
| Les droits métier | `ProjectScope`, `DroitEffectif`, `CommercialAccessPolicy`… : chaque application a les siens, et ce sont les MÊMES que ses écrans |
| La validation par aperçu puis confirmation de google-ads | une seule application en a l'usage ; elle montera le jour où une deuxième en aura besoin |
| Le contrôle d'un fichier déposé (image, PDF) | le réceptionnaire de l'application, qui s'appuie sur `qcdgone/builder` pour une image ; le bundle ne porte que l'adresse et sa réception |
| L'édition du document builder (`bloc_*`, `rangee_*`) | les opérations sur le document vont dans `qcdgone/builder`, sans notion de MCP ; seul le domaine d'outils viendra ici |

⚠ **Ne jamais accepter de clé d'API sur le serveur.** Une clé ne désigne personne.
Le serveur agit comme la personne connectée, et c'est ce qui garantit que l'agent
voit ce qu'elle voit, et pas plus.

## Les invariants

- **Deux sortes d'échec, deux canaux.**
  - Un appel mal formé est une **erreur de protocole** (-32602).
  - Un objet introuvable, une écriture refusée ou un appel refusé par une garde sont
    un **résultat** `isError`, que l'agent lit.
  - Mélanger les deux fait réessayer l'agent au lieu de dire le refus.
- **Le catalogue valide AVANT les gardes, et les gardes jouent AVANT le domaine.**
  - Un appel mal formé n'entame pas le plafond.
  - Un domaine ne peut pas oublier une garde.
- **Une exception inattendue REMONTE**, après avoir été journalisée. La convertir en
  `isError` masquerait un défaut sous un message que l'agent recopierait poliment.
- **Un appelant qui n'implémente pas `UtilisateurMcpInterface` est refusé.** Le
  défaut ferme ; il n'ouvre jamais.
- **Un domaine réservé (`DomaineReserve`) n'existe pas pour qui n'a pas son
  rôle** : ni listé, ni appelable, ni nommé dans les instructions. Sans
  vérificateur de droits, il est fermé à tous. La visibilité se calcule à chaque
  lecture, jamais gardée : le catalogue survit aux requêtes, l'appelant non.
- **Une adresse de dépôt sert UNE fois**, consommée au premier `PUT` même
  refusé, et seule l'empreinte de son jeton est gardée. Le contenu du fichier
  est l'affaire du réceptionnaire de l'application, jamais du bundle, qui ne
  dépend pas de `qcdgone/builder`.
- **L'appelant délégué garde le format `utilisateur:{id}`.** Le changer remet à zéro
  les décomptes du jour et coupe l'historique du journal en deux.
- **Le chemin `/api/mcp` est fixe.** Il est aussi le motif du pare-feu et le
  `resource_path` du SSO, et trois réglages pour une seule adresse finissent par
  diverger.

## Tests

Mêmes règles que `qcdgone/api` : tout correctif arrive avec le test qui l'aurait
attrapé, vu rouge au moins une fois, et il est interdit de rendre un test vert en
trichant.

```bash
composer install          # vendor/ n'est pas versionné
vendor/bin/phpunit        # sur le PHP de l'hôte, sans Docker
./release.sh minor "…"    # joue les tests, et refuse de publier si l'un rougit
```

⚠ **`ChargementDuBundleTest` compile un vrai conteneur.** Des tests unitaires sur
des services ne prouvent rien du câblage : la v0.4.0 de `qcdgone/api` est partie
avec une extension qui ne compilait pas, alors que toutes les suites étaient vertes.

⚠ **Ce qui se joue entre ce bundle et `qcdgone/sso`** (le jeton, le 401, l'ordre des
pare-feux) ne se voit dans aucun des deux. C'est le test fonctionnel de chaque
application qui le couvre, avec `Qcdgone\Mcp\Test\AppelsMcp`.
