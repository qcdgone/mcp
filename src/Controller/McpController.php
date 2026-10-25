<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Controller;

use Qcdgone\Api\Doc\ApiDoc;
use Qcdgone\Mcp\Securite\UtilisateurMcpInterface;
use Qcdgone\Mcp\ServeurMcp;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le point d'entrée MCP : un assistant (Claude…) agit au nom de la personne
 * connectée au hub, avec ses droits.
 *
 * ⚠ **Son propre pare-feu, `mcp`, déclaré par l'APPLICATION** (voir le README) :
 * il n'accepte QUE les jetons du hub (`qcdgone/sso`, `AuthenticatorDeJeton`),
 * jamais une clé d'API. L'appel SANS en-tête reçoit `WWW-Authenticate`, qui
 * désigne le hub : c'est ce dont un client MCP a besoin pour se connecter seul.
 *
 * ⚠ **Les `#[ApiDoc]` ne coûtent rien sans `qcdgone/api`** : un attribut n'est
 *   instancié que lorsqu'on le lit, et seul le catalogue de ce bundle-là le lit.
 *   Avec lui, ils évitent que la garde « route sans documentation » ne dénonce
 *   la route du serveur dans chaque application.
 */
final readonly class McpController
{
    public function __construct(
        private ServeurMcp $serveur,
        private Security $securite,
    ) {
    }

    #[ApiDoc(
        resume: 'Model Context Protocol server',
        description: 'Connect an AI assistant (Claude…) to this application. The endpoint speaks MCP over HTTP (JSON-RPC, JSON responses, no SSE). '
            .'⚠ It takes an OAuth access token from the identity hub, NEVER an API key — the assistant acts as the person who signed in '
            .'and sees exactly what that person sees on screen. The first unauthenticated call answers 401 with a WWW-Authenticate header '
            .'pointing to /.well-known/oauth-protected-resource, from which the client finds the hub by itself. Access withdrawn in the hub '
            .'closes it within fifteen minutes at most — the lifetime of an access token. The tools and what they do are listed by tools/list.',
        corps: ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ressource: 'mcp',
        erreurs: [
            '401' => 'Missing, expired or invalid hub token, or a token issued for another application.',
            '403' => 'The account was revoked by the identity hub.',
        ],
    )]
    public function traiter(Request $requete): Response
    {
        $utilisateur = $this->securite->getUser();

        // ⚠ Le hub est l'autorité sur l'identité : un compte révoqué ne lit plus
        //   rien, même avec un jeton encore valide quelques minutes. Un appelant
        //   que le bundle ne sait pas lire est refusé de même.
        if (!$utilisateur instanceof UtilisateurMcpInterface || $utilisateur->isRevoked()) {
            return new JsonResponse(ServeurMcp::erreur(null, ServeurMcp::REQUETE_INVALIDE, 'Access denied.'), Response::HTTP_FORBIDDEN);
        }

        try {
            $message = json_decode($requete->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(ServeurMcp::erreur(null, ServeurMcp::ERREUR_ANALYSE, 'Parse error.'), Response::HTTP_BAD_REQUEST);
        }

        $reponse = $this->serveur->traiter($message);

        // Une notification est acquittée sans corps.
        if (null === $reponse) {
            return new Response('', Response::HTTP_ACCEPTED);
        }

        return new JsonResponse($reponse);
    }

    /** ⚠ Aucun flux ouvert par le serveur : 405 au GET dit au client de s'en tenir aux POST. */
    #[ApiDoc(
        resume: 'Server-sent stream (not offered)',
        description: 'Always 405: this server never pushes messages; every answer comes back on the POST that asked for it.',
        ressource: 'mcp',
    )]
    public function flux(): Response
    {
        return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }
}
