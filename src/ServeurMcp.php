<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\Journal\JournalDesAppels;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le protocole MCP, en transport HTTP « streamable », réponses JSON. Écrit pour
 * serelios (SRL-5), recopié dans qcdeal, carport et mcp-google-ads, puis remonté
 * ici pour qu'il n'existe plus qu'une fois. Les outils sont dans
 * {@see CatalogueDesOutils}.
 *
 * ⚠ **Sans bibliothèque, et c'est un choix.** Un serveur d'outils se
 * réduit à quatre méthodes JSON-RPC — `initialize`, `ping`, `tools/list`,
 * `tools/call` — et à leurs erreurs. Une dépendance de plus serait une surface
 * d'attaque de plus sur une route exposée à tout porteur d'un jeton du parc.
 *
 * ⚠ **Aucun flux SSE** : chaque requête reçoit sa réponse en `application/json`,
 * ce que la spécification autorise. Le serveur n'envoie rien de lui-même.
 *
 * ⚠ **Sans état** : aucune session MCP n'est ouverte, l'identité vient du jeton
 * à CHAQUE requête. C'est ce qui fait qu'une révocation s'applique au premier
 * appel qui suit l'expiration du jeton, sans rien à invalider ici.
 */
final readonly class ServeurMcp
{
    /** Les versions du protocole comprises, la plus récente d'abord. */
    public const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const ERREUR_ANALYSE = -32700;
    public const REQUETE_INVALIDE = -32600;
    public const METHODE_INCONNUE = -32601;
    public const PARAMETRES_INVALIDES = -32602;

    /**
     * @param array{name: string, title: string, version: string, instructions: string, website_path: string|null, icon: array{path: string|null, mime_type: string, sizes: list<string>}} $presentation
     */
    public function __construct(
        private CatalogueDesOutils $outils,
        private RequestStack $requetes,
        private array $presentation,
        private ?JournalDesAppels $journal = null,
    ) {
    }

    /**
     * @param mixed $message le corps JSON décodé
     *
     * @return array<string, mixed>|null la réponse, `null` pour une notification
     */
    public function traiter(mixed $message): ?array
    {
        // ⚠ Les lots JSON-RPC ont été retirés du protocole (2025-06-18) : un
        // tableau est refusé plutôt qu'interprété à moitié.
        if (!\is_array($message) || array_is_list($message) || '2.0' !== ($message['jsonrpc'] ?? null) || !\is_string($message['method'] ?? null)) {
            return self::erreur(null, self::REQUETE_INVALIDE, 'Invalid JSON-RPC request.');
        }

        $id = $message['id'] ?? null;
        $methode = $message['method'];
        $parametres = \is_array($message['params'] ?? null) ? $message['params'] : [];

        // Une notification (sans identifiant) n'appelle aucune réponse.
        if (!\array_key_exists('id', $message)) {
            return null;
        }

        try {
            $resultat = match ($methode) {
                'initialize' => $this->initialiser($parametres),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->outils->definitions()],
                'tools/call' => $this->appeler($parametres),
                default => null,
            };
        } catch (ArgumentsInvalides $e) {
            return self::erreur($id, self::PARAMETRES_INVALIDES, $e->getMessage());
        }

        if (null === $resultat) {
            return self::erreur($id, self::METHODE_INCONNUE, \sprintf('Method not found: %s', $methode));
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultat];
    }

    /** @return array<string, mixed> */
    public static function erreur(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed> $parametres
     *
     * @return array<string, mixed>
     */
    private function initialiser(array $parametres): array
    {
        $demandee = $parametres['protocolVersion'] ?? null;
        $p = $this->presentation;

        $serveur = ['name' => $p['name'], 'title' => $p['title'], 'version' => $p['version']];
        if (null !== $p['icon']['path']) {
            // ⚠ L'icône du connecteur (MCP 2025-11-25) : ignorée par un client
            //   plus ancien, qui se rabat sur le favicon du domaine.
            $serveur['icons'] = [array_filter([
                'src' => $this->adresse($p['icon']['path']),
                'mimeType' => $p['icon']['mime_type'],
                'sizes' => $p['icon']['sizes'],
            ], static fn (mixed $v): bool => [] !== $v)];
        }
        if (null !== $p['website_path']) {
            $serveur['websiteUrl'] = $this->adresse($p['website_path']);
        }

        return [
            // ⚠ On rend la version DEMANDÉE si on la comprend, sinon la plus
            // récente : c'est au client de raccrocher s'il ne la parle pas.
            'protocolVersion' => \in_array($demandee, self::VERSIONS, true) ? $demandee : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => $serveur,
            'instructions' => $p['instructions'],
        ];
    }

    /** Une adresse absolue sur l'hôte de la requête — recette et production servent le même code. */
    private function adresse(string $chemin): string
    {
        return ($this->requetes->getCurrentRequest()?->getSchemeAndHttpHost() ?? '').$chemin;
    }

    /**
     * ⚠ **Deux sortes d'échec, deux canaux** : un appel mal formé est une
     * ERREUR de protocole (l'agent s'est trompé d'outil ou d'argument) ; un objet
     * introuvable, une écriture ou un appel refusés sont un RÉSULTAT en échec
     * (`isError`), que l'agent lit et dont il peut tenir compte.
     *
     * @param array<string, mixed> $parametres
     *
     * @return array<string, mixed>
     */
    private function appeler(array $parametres): array
    {
        $nom = $parametres['name'] ?? null;
        $arguments = $parametres['arguments'] ?? [];

        if (!\is_string($nom) || !$this->outils->existe($nom)) {
            throw new ArgumentsInvalides(\sprintf('Outil inconnu : %s', \is_string($nom) ? $nom : '?'));
        }

        if (!\is_array($arguments) || ([] !== $arguments && array_is_list($arguments))) {
            throw new ArgumentsInvalides('arguments : un objet est attendu.');
        }

        // ⚠ **Chaque appel d'un outil connu est journalisé, quelle qu'en soit
        //   l'issue** : un refus ou une erreur en dit autant qu'un succès sur ce
        //   qu'un agent a TENTÉ de faire.
        $debut = hrtime(true);
        $consigner = function (IssueDAppel $issue, ?string $code = null) use ($nom, $arguments, $debut): void {
            $this->journal?->consigner($nom, $arguments, (int) ((hrtime(true) - $debut) / 1_000_000), $issue, $code);
        };

        try {
            $charge = $this->outils->appeler($nom, $arguments);
        } catch (ArgumentsInvalides $e) {
            $consigner(IssueDAppel::Erreur, 'arguments_invalides');

            throw $e;
        } catch (AppelRefuse $e) {
            $consigner(IssueDAppel::Refuse, $e->code_);

            return self::echec(['error' => $e->getMessage(), 'code' => $e->code_] + $e->details);
        } catch (ObjetIntrouvable $e) {
            $consigner(IssueDAppel::Erreur, 'objet_introuvable');

            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (EcritureRefusee $e) {
            $consigner(IssueDAppel::Erreur, 'ecriture_refusee');

            // ⚠ Le détail champ par champ voyage avec le refus : l'agent doit
            // pouvoir corriger et réessayer.
            return self::echec(['error' => $e->getMessage()] + $e->details);
        } catch (EchecPourLAgent $e) {
            $consigner(IssueDAppel::Erreur, $e->codeDEchec());

            return self::echec($e->pourLAgent());
        } catch (\Throwable $e) {
            $consigner(IssueDAppel::Erreur, (new \ReflectionClass($e))->getShortName());

            throw $e;
        }

        $consigner(IssueDAppel::Ok);

        return [
            'content' => [['type' => 'text', 'text' => self::json($charge)]],
            'structuredContent' => $charge,
            'isError' => false,
        ];
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array<string, mixed>
     */
    private static function echec(array $charge): array
    {
        return [
            'content' => [['type' => 'text', 'text' => self::json($charge)]],
            'structuredContent' => $charge,
            'isError' => true,
        ];
    }

    /** @param array<string, mixed> $charge */
    private static function json(array $charge): string
    {
        return (string) json_encode($charge, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
