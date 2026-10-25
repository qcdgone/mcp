<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Test;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Qcdgone\Mcp\Securite\UtilisateurMcpInterface;
use Qcdgone\Sso\Ressource\ClesDuHub;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Parler au serveur MCP avec de VRAIS jetons signés, depuis un `WebTestCase`
 * de l'application. Recopié quatre fois avant de vivre ici.
 *
 * ⚠ **Le test se donne ses propres clés** : un JWKS complaisant câblé pour
 * toute la suite lui donnerait un moyen de s'authentifier au hub sans le dire.
 * Ici, la paire RSA naît dans le test et meurt avec lui.
 *
 * ⚠ **Exige `$this->navigateur` et `disableReboot()`** avant
 * {@see brancherUnHubDeTest()} : sans lui, le noyau redémarre entre deux
 * requêtes et reprend le hub injoignable de la configuration de test.
 *
 * ⚠ **L'audience est le `SSO_CLIENT_ID` de `.env.test`** ; un test qui veut un
 * jeton émis pour une AUTRE application la passe à {@see envoyer()}.
 *
 * @property \Symfony\Bundle\FrameworkBundle\KernelBrowser $navigateur
 */
trait AppelsMcp
{
    private const EMETTEUR = 'http://127.0.0.1:9';
    private const KID = 'cle-de-test';

    private string $clePrivee;

    protected function brancherUnHubDeTest(): void
    {
        $paire = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $paire);
        openssl_pkey_export($paire, $prive);
        $this->clePrivee = (string) $prive;
        $details = openssl_pkey_get_details($paire);
        \assert(\is_array($details));

        $b64 = static fn (string $binaire): string => rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
        $jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => self::KID,
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]];

        $hub = new MockHttpClient(static function (string $methode, string $url) use ($jwks): MockResponse {
            return match (true) {
                str_ends_with($url, '/.well-known/oauth-authorization-server') => new MockResponse((string) json_encode([
                    'issuer' => self::EMETTEUR, 'jwks_uri' => self::EMETTEUR.'/jwks',
                ])),
                str_ends_with($url, '/jwks') => new MockResponse((string) json_encode($jwks)),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        self::getContainer()->set(ClesDuHub::class, new ClesDuHub($hub, new ArrayAdapter(), self::EMETTEUR));
    }

    /**
     * L'appel d'un outil, erreur comprise — pour vérifier qu'un objet est
     * « introuvable » hors de portée.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function outilBrut(UtilisateurMcpInterface $utilisateur, string $nom, array $arguments = []): array
    {
        return $this->appel($utilisateur, 'tools/call', ['name' => $nom, 'arguments' => (object) $arguments]);
    }

    /**
     * Un appel d'outil qui DOIT réussir : rend `structuredContent`.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function outil(UtilisateurMcpInterface $utilisateur, string $nom, array $arguments = []): array
    {
        $reponse = $this->outilBrut($utilisateur, $nom, $arguments);
        self::assertFalse($reponse['result']['isError'] ?? true, (string) json_encode($reponse));

        return $reponse['result']['structuredContent'];
    }

    /**
     * @param array<string, mixed> $parametres
     *
     * @return array<string, mixed>
     */
    protected function appel(UtilisateurMcpInterface $utilisateur, string $methode, array $parametres = []): array
    {
        $this->envoyer($utilisateur, ['jsonrpc' => '2.0', 'id' => 7, 'method' => $methode, 'params' => (object) $parametres]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->navigateur->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $message */
    protected function envoyer(UtilisateurMcpInterface $utilisateur, array $message, ?string $audience = null): void
    {
        $this->navigateur->request('POST', '/api/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->jeton_($utilisateur, $audience),
        ], (string) json_encode($message));
    }

    /**
     * Un jeton d'accès signé comme le hub le signerait.
     *
     * ⚠ La revendication `email` porte `getUserIdentifier()` : c'est par elle
     *   que `AuthenticatorDeJeton` retrouve le compte, et les quatre applications
     *   du parc identifient leurs comptes par l'adresse.
     */
    protected function jeton_(UtilisateurMcpInterface $utilisateur, ?string $audience = null): string
    {
        $horloge = self::getContainer()->get(ClockInterface::class);
        \assert($horloge instanceof ClockInterface);
        $maintenant = $horloge->now();

        $configuration = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($this->clePrivee), InMemory::plainText('inutilisee'));

        return $configuration->builder()
            ->issuedBy(self::EMETTEUR)
            ->permittedFor($audience ?? self::audienceDeTest())
            ->relatedTo((string) $utilisateur->getId())
            ->issuedAt($maintenant)
            // `StrictValidAt` exige les trois dates, comme le hub les pose.
            ->canOnlyBeUsedAfter($maintenant)
            ->expiresAt($maintenant->modify('+10 minutes'))
            ->withClaim('email', $utilisateur->getUserIdentifier())
            ->withHeader('kid', self::KID)
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /** Le `SSO_CLIENT_ID` de `.env.test` : l'audience qu'attend l'application. */
    protected static function audienceDeTest(): string
    {
        $audience = $_SERVER['SSO_CLIENT_ID'] ?? $_ENV['SSO_CLIENT_ID'] ?? null;
        \assert(\is_string($audience) && '' !== $audience, 'SSO_CLIENT_ID absent de l’environnement de test.');

        return $audience;
    }
}
