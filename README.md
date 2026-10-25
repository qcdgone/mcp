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

## Gardes et journal

- Une **garde** implémente `Garde\GardeDAppel`. Elle joue avant chaque outil,
  après la validation. `#[AsTaggedItem(priority: …)]` règle l'ordre, la plus
  haute d'abord. Le bundle en fournit une, `LimiteParUtilisateur` (priorité
  100), active dès que `limite_par_utilisateur` nomme un limiteur de
  `framework.rate_limiter`.
- Un **journal** implémente `Journal\JournalDesAppels`, aliasé sur l'interface.
  Il reçoit chaque appel d'un outil connu, quelle qu'en soit l'issue, avec des
  arguments BRUTS : il les passe par `ParametresSansSecret::de()` avant de les
  écrire.

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
| `GardeDAppel::verifier(…, list<Cible>)` | `verifier(…, Arguments)` : la garde résout ses cibles |
| `Outil::lecture()` → `openWorldHint: true` (google-ads) | passer `exterieur: true` |
