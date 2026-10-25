<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Journal;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\Journal\ParametresSansSecret;

final class ParametresSansSecretTest extends TestCase
{
    /** ⚠ À toute profondeur, et la clé reste : le journal doit dire qu'un jeton a été présenté. */
    public function testLesSecretsEtLesCoordonneesSontMasquesAToutesProfondeurs(): void
    {
        self::assertSame(
            ['company_id' => 3, 'confirm_token' => '***', 'contact' => ['email' => '***', 'ville' => 'Lyon', 'telephone' => '***'], 'leads' => [['Email' => '***']]],
            ParametresSansSecret::de(['company_id' => 3, 'confirm_token' => 'abc', 'contact' => ['email' => 'a@b.c', 'ville' => 'Lyon', 'telephone' => '0601'], 'leads' => [['Email' => 'x@y.z']]]),
        );
    }
}
