<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Garde;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\AppelRefuse;
use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\Garde\LimiteParUtilisateur;
use Qcdgone\Mcp\Tests\Double\UtilisateurDEssai;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class LimiteParUtilisateurTest extends TestCase
{
    public function testAuDelaDuPlafondLAgentApprendQuandReessayer(): void
    {
        $limiteurs = new RateLimiterFactory(['id' => 'mcp', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $garde = new LimiteParUtilisateur($limiteurs, $this->securite(new UtilisateurDEssai(5)));

        $garde->verifier('lire', [], new Arguments([]));
        $garde->verifier('lire', [], new Arguments([]));

        try {
            $garde->verifier('lire', [], new Arguments([]));
            self::fail('Le troisième appel aurait dû être refusé.');
        } catch (AppelRefuse $e) {
            self::assertSame('plafond_utilisateur', $e->code_);
            self::assertGreaterThan(0, $e->details['reessayer_dans_s']);
        }
    }

    /** ⚠ Le plafond est PAR personne : l'une qui l'épuise ne bloque pas l'autre. */
    public function testLePlafondDUnePersonneNEntamePasCeluiDUneAutre(): void
    {
        $limiteurs = new RateLimiterFactory(['id' => 'mcp', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());

        (new LimiteParUtilisateur($limiteurs, $this->securite(new UtilisateurDEssai(5))))->verifier('lire', [], new Arguments([]));
        (new LimiteParUtilisateur($limiteurs, $this->securite(new UtilisateurDEssai(6))))->verifier('lire', [], new Arguments([]));

        $this->expectException(AppelRefuse::class);
        (new LimiteParUtilisateur($limiteurs, $this->securite(new UtilisateurDEssai(5))))->verifier('lire', [], new Arguments([]));
    }

    private function securite(UtilisateurDEssai $utilisateur): Security
    {
        $jetons = new TokenStorage();
        $jetons->setToken(new UsernamePasswordToken($utilisateur, 'mcp', $utilisateur->getRoles()));
        $conteneur = new Container();
        $conteneur->set('security.token_storage', $jetons);

        return new Security($conteneur);
    }
}
