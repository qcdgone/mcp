# qcdgone/mcp

Le serveur MCP des applications du parc : un assistant (Claude…) s'y connecte
avec le compte de la personne, par le hub, et agit avec ses droits.

**Le bundle porte le protocole, l'application porte les outils.** Il parle
JSON-RPC sur `POST /api/mcp`, valide les arguments d'après le schéma de chaque
outil, joue les gardes, journalise, et rend les échecs sous la forme que
l'agent sait lire. Ce que font les outils, qui peut les appeler sur quoi, et ce
que l'agent doit savoir avant de commencer restent dans l'application.

| | Où |
|---|---|
| Identité : jeton du hub, métadonnées OAuth, `WWW-Authenticate` | `qcdgone/sso` (requis) |
| Quota et journal par requête HTTP | `qcdgone/api` (facultatif, branché d'office) |
| Protocole, catalogue, gardes, journal des outils, trait de test | **ce bundle** |
| Les outils, les droits métier, les instructions | l'application |

## Installation

```bash
composer require qcdgone/mcp
```

```php
// config/bundles.php — après QcdgoneSsoBundle
Qcdgone\Mcp\QcdgoneMcpBundle::class => ['all' => true],
```

```yaml
# config/routes/qcdgone_mcp.yaml
qcdgone_mcp:
    resource: '@QcdgoneMcpBundle/config/routes.yaml'
```

Les routes s'appellent `qcdgone_mcp` (POST) et `qcdgone_mcp_flux` (GET → 405).
Le chemin est **fixe** : `/api/mcp`.

```yaml
# config/packages/qcdgone_mcp.yaml
qcdgone_mcp:
    name: qcdeal                       # serverInfo.name
    title: 'QCDeal — pipe commercial'  # le nom du connecteur
    instructions: >-
        Access to the sales pipeline (CRM)… Start with list_companies…
    icon: { path: /images/icone-256.png, sizes: ['256x256'] }   # facultatif
    website_path: /                    # null pour ne pas l'annoncer
    limite_par_utilisateur: ~          # nom d'un limiteur, ou rien
```

### Le pare-feu : à déclarer par l'application

⚠ Le bundle ne déclare **aucun** pare-feu : leur ordre est arbitré par chaque
application. Le bloc, **avant** le pare-feu `api` s'il existe :

```yaml
# config/packages/security.yaml
firewalls:
    mcp:
        pattern: ^/api/mcp$
        stateless: true
        provider: app_user_provider
        custom_authenticators: [ Qcdgone\Sso\Security\AuthenticatorDeJeton ]
        entry_point: Qcdgone\Sso\Security\AuthenticatorDeJeton
access_control:
    - { path: ^/api/mcp$, roles: ROLE_USER }    # ou ROLE_SUPER_ADMIN
```

```yaml
# config/packages/qcdgone_sso.yaml
qcdgone_sso:
    resource_path: '/api/mcp'
```

⚠ **Jamais `ApiKeyAuthenticator` sur ce pare-feu.** Une clé d'API ne désigne
personne ; le serveur agit comme une personne.

⚠ **L'`entry_point` est `AuthenticatorDeJeton`** : c'est lui qui répond 401 avec
`WWW-Authenticate … resource_metadata`. C'est ce que lit un client MCP pour
trouver le hub par lui-même.

### L'utilisateur

L'entité `User` implémente `Qcdgone\Mcp\Securite\UtilisateurMcpInterface` :
`getId(): ?int` et `isRevoked(): bool`, que les comptes du parc portent déjà.

⚠ **Un appelant qui ne l'implémente pas est refusé (403).** Le bundle ne devine
pas comment lire une révocation : oublier l'`implements` ferme le serveur, au
lieu de laisser entrer les comptes révoqués.

## Écrire des outils

Un domaine implémente `Qcdgone\Mcp\DomaineMcp`. L'autoconfiguration le recense,
il n'y a rien d'autre à déclarer.

```php
final readonly class Affaires implements DomaineMcp
{
    public function definitions(): array
    {
        return [
            Outil::lecture('get_deal', 'Read a deal', 'One deal, with its timeline.', [
                'deal_id' => Outil::entier('The deal id.', min: 1),
            ], ['deal_id']),
        ];
    }

    public function appeler(string $nom, Arguments $arguments): array
    {
        return match ($nom) {
            'get_deal' => $this->fiche($arguments->entierObligatoire('deal_id')),
        };
    }
}
```

| Le domaine lève… | L'agent reçoit… |
|---|---|
| `ArgumentsInvalides` | une erreur JSON-RPC -32602 : il s'est trompé d'argument |
| `ObjetIntrouvable` | un résultat `isError` (texte) — **« introuvable », jamais « interdit »**, pour un objet hors de portée |
| `EcritureRefusee($message, $details)` | un résultat `isError`, avec le détail champ par champ |
| `AppelRefuse($message, $code, $details)` | un résultat `isError`, avec un `code` stable |
| une exception qui implémente `EchecPourLAgent` | un résultat `isError`, avec la charge qu'elle rend |
| toute autre exception | **elle remonte** : c'est un défaut, et il doit se voir |

Le catalogue refuse un champ inconnu ou un champ obligatoire manquant **avant**
d'appeler le domaine : le schéma annonce `additionalProperties: false`, et le
catalogue l'applique.

### Un domaine réservé à un rôle

Un domaine qui implémente `Qcdgone\Mcp\DomaineReserve` n'existe que pour son
rôle : `role()` est passé à `isGranted()` à chaque appel, `consignes()` est
ajouté aux instructions du serveur pour qui le voit seulement.

```php
final readonly class Facturation implements DomaineReserve
{
    public function role(): string { return 'ROLE_SUPER_ADMIN'; }
    public function consignes(): string { return 'Billing: start with accounts_list…'; }
    // definitions(), appeler() : comme un DomaineMcp
}
```

- Pour qui n'a pas le rôle, ses outils ne sont **ni listés, ni appelables**
  (« outil inconnu », mot pour mot comme un nom qui n'existe pas), et ses
  consignes n'apparaissent pas.
- **Le défaut ferme** : sans `security.authorization_checker`, personne ne voit
  un domaine réservé.
- Le pare-feu ouvre alors `/api/mcp` au rôle le plus bas (`ROLE_USER`) ; c'est
  le rôle de chaque domaine qui trie.
- Les droits sur un objet (ce projet, cette entreprise) restent dans le domaine.

## Recevoir un fichier : les adresses de dépôt

Un agent ne peut pas joindre un fichier à un appel d'outil, et le recopier en
base64 dans ses arguments coûte des centaines de milliers de caractères. Un
outil de l'application ouvre donc une **adresse à usage unique** ; l'agent y
envoie le binaire en `PUT`, sans encodage ni jeton (`curl --data-binary`).

1. **Un réceptionnaire** par nature de fichier, recensé par l'autoconfiguration.
   Le contrôle du contenu est le sien : pour une image, `qcdgone/builder`
   (`ImageUploadHandler::depuisDesOctets()`).

```php
final readonly class ReceveurDImages implements ReceveurDeDepot
{
    public function nature(): string { return 'image'; }
    public function octetsMax(): int { return 8 * 1024 * 1024; }
    public function recevoir(string $octets, DepotOuvert $depot): array
    {
        try {
            $image = $this->images->depuisDesOctets($octets, 'uploads/pages', 1600);
        } catch (ImageUploadException $e) {
            throw new DepotRefuse($e->getMessage());   // 422, rien n'est écrit
        }

        return ['chemin' => $image['url']];           // la réponse au PUT
    }
}
```

2. **Un outil** qui rend l'adresse, dans le domaine qui a le droit d'écrire :
   `$this->depots->ouvrir('image', $utilisateur)` rend `adresse`, `methode`,
   `expire_le`, `octets_max` et un `exemple` de `curl`.
3. **Le pare-feu** : la route `PUT /api/mcp/depot/{jeton}` (`qcdgone_mcp_depot`)
   n'a pas de jeton du hub, et le motif `^/api/mcp$` du pare-feu `mcp` ne la
   couvre pas. À placer AVANT `main` :

```yaml
firewalls:
    mcp_depot:
        pattern: ^/api/mcp/depot/
        stateless: true
        security: false
```

- **Usage unique, consommé au premier `PUT`, réussi ou non**, expiré après
  `depot_duree_s` (900 s par défaut, de 60 à 3600). Le jeton n'est jamais gardé :
  la clé du cache (`cache.app`) est son empreinte.
- Réponses : 200 (ce que rend le réceptionnaire), 400 corps vide, 410 adresse
  inconnue, expirée ou déjà utilisée, 413 trop lourd (vérifié sur
  `Content-Length` avant lecture, puis lecture arrêtée au plafond), 422 refusé
  par le réceptionnaire.
- **Avec `symfony/lock`** (`lock.factory`), le second de deux `PUT` simultanés est
  refusé ; sans lui, les deux peuvent passer.
- **Le journal** : l'émission est un appel d'outil (`JournalDesAppels`) ; le
  dépôt passe par `Journal\JournalDesDepots`, facultatif, qui reçoit le dépôt
  (nature, compte) et le poids, jamais le contenu.
- ⚠ L'adresse figure dans les journaux d'accès du serveur web : elle n'y sert
  plus, puisqu'elle est consommée.
- Sans réceptionnaire, rien ne change : toute adresse est inconnue (410).

## Gardes et journal

- Une **garde** implémente `Garde\GardeDAppel`. Elle joue avant chaque outil,
  après la validation. `#[AsTaggedItem(priority: …)]` règle l'ordre, la plus
  haute d'abord. Le bundle en fournit une, `LimiteParUtilisateur` (priorité
  100), active dès que `limite_par_utilisateur` nomme un limiteur de
  `framework.rate_limiter`.
- Une **préparation** implémente `Appel\PreparationDesArguments`. Elle joue
  après la validation du schéma et **avant** les gardes, et ce qu'elle rend est
  ce que lisent les gardes ET le domaine : un identifiant normalisé
  (`123-456-7890` → `1234567890`), un objet désigné autrement résolu en son
  identifiant. Elle lève `ArgumentsInvalides` (-32602, aucun plafond entamé) ou
  `ObjetIntrouvable`.
- Une **enveloppe** implémente `Appel\EnveloppeDAppel`. Elle entoure le SEUL
  appel au domaine, après les gardes (un cache ouvert le temps d'un outil de
  lecture). La priorité la plus haute est la plus extérieure.
- Un **journal** implémente `Journal\JournalDesAppels`, aliasé sur l'interface.
  Il reçoit chaque appel d'un outil connu, quelle qu'en soit l'issue, avec des
  arguments BRUTS : il les passe par `ParametresSansSecret::de()` avant de les
  écrire. Une ressource visée se relit depuis ces arguments ; leur forme
  normalisée est celle de la préparation, à rejouer.

L'ordre d'un appel : schéma → préparations → gardes → enveloppes → domaine.

⚠ **Pourquoi la normalisation n'est pas dans une garde** (décidé pour
mcp-google-ads, le 26/10/2026) : une garde reçoit des `Arguments` qu'elle ne
peut pas modifier, et le domaine lirait la forme brute qu'elle a contrôlée
sous une autre ; et la garde de débit (priorité 100) passerait avant, si bien
qu'un identifiant mal formé entamerait le plafond.

## Quota de `qcdgone/api`

⚠ Sans appelant délégué, un appel par jeton du hub **échappe au quota ET au
journal** `api_call`. Le bundle branche donc `Securite\AppelantDelegue`
(`utilisateur:{id}`) dès que `qcdgone/api` est installé, sauf si l'application
aliase déjà `DelegatedCallerResolverInterface`.

## Tests de l'application

```php
use Qcdgone\Mcp\Test\AppelsMcp;

final class LeServeurMcpTest extends WebTestCase
{
    use AppelsMcp;

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->navigateur->disableReboot();
        $this->brancherUnHubDeTest();
    }

    public function testLaFicheSeLit(): void
    {
        $fiche = $this->outil($utilisateur, 'get_deal', ['deal_id' => 3]);
        // …
    }
}
```

Le trait signe de **vrais** jetons RS256, avec une paire de clés créée pour le
test, et sert un faux hub à `ClesDuHub`. L'audience est le `SSO_CLIENT_ID` de
`.env.test`.

## Passer d'une copie locale au bundle

Les quatre copies `App\Mcp\*` du parc ont la même forme. La migration consiste
surtout à changer d'espace de noms :

| Avant (application) | Après |
|---|---|
| `App\Mcp\ServeurMcp`, `App\Controller\Api\McpController` | supprimés : route du bundle |
| `App\Mcp\{ArgumentsInvalides, ObjetIntrouvable, EcritureRefusee, AppelRefuse}` | `Qcdgone\Mcp\…` |
| `App\Mcp\{DomaineMcp, Arguments, Outil, CatalogueDesOutils}` | `Qcdgone\Mcp\…` |
| `App\Mcp\AppelantDelegue` | supprimé (sauf format de référence propre) |
| `tests/AppelsMcp.php` | `Qcdgone\Mcp\Test\AppelsMcp` |
| `path('app_api_mcp')` | `path('qcdgone_mcp')` |
| tag `app.mcp.domaine` / `app.mcp.garde` | aucun : autoconfiguration |
| `GardeDAppel::verifier(…, list<Cible>)` | `verifier(…, Arguments)` : la garde relit ses cibles dans les arguments préparés |
| `ResolveurDeCibles` appelé par le catalogue (google-ads) | une `Appel\PreparationDesArguments` |
| `LecturesEnCache::pendant()` dans le catalogue (google-ads) | une `Appel\EnveloppeDAppel` |
| `ErreurGoogleAds` interceptée par le serveur (google-ads) | `implements EchecPourLAgent` |
| familles d'outils filtrées par rôle dans le serveur (swan) | `DomaineReserve` |
| `Outil::lecture()` → `openWorldHint: true` (google-ads) | passer `exterieur: true` |
