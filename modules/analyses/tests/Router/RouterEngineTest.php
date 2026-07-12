<?php

declare(strict_types=1);

namespace Analyses\Tests\Router;

use Analyses\Ontology\StudyDesign;
use Analyses\Router\RouterEngine;
use PHPUnit\Framework\TestCase;

/**
 * Routage composite déterministe (SPECS §28 : testable sans modèle génératif).
 */
final class RouterEngineTest extends TestCase
{
    private RouterEngine $router;

    protected function setUp(): void
    {
        $this->router = new RouterEngine();
    }

    public function testCrossSectionalRoutesToAxisAndStrobe(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::CrossSectional, ['prevalence', 'association'], ['psychology'], ['questionnaire']);

        self::assertSame(['axis'], $plan['primary_frameworks']);
        self::assertContains('strobe_cross_sectional', $plan['reporting_frameworks']);
        self::assertContains('observational_bias_core', $plan['risk_of_bias_tools']);
        // Surcouches : domaine (psychométrie) + modalité (biais déclaratif) + finalité.
        self::assertContains('psychometric_validity', $plan['analysis_modules']);
        self::assertContains('declarative_bias', $plan['analysis_modules']);
        self::assertContains('no_causal_inference', $plan['analysis_modules']);
        // Interdiction d'inférence causale attendue pour une transversale.
        self::assertNotContains('consort', $plan['reporting_frameworks']);
    }

    public function testRctRoutesToRob2AndConsort(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::RandomizedControlledTrial);

        self::assertContains('rob2', $plan['risk_of_bias_tools']);
        self::assertContains('consort', $plan['reporting_frameworks']);
        self::assertContains('intention_to_treat', $plan['analysis_modules']);
    }

    public function testSystematicReviewRoutesToAmstar2AndPrisma(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::SystematicReview);

        self::assertSame(['amstar2'], $plan['primary_frameworks']);
        self::assertContains('prisma', $plan['reporting_frameworks']);
        self::assertContains('robis', $plan['risk_of_bias_tools']);
    }

    public function testUnknownStillCarriesTransverseCores(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::Unknown);

        self::assertContains('integrity_core', $plan['analysis_modules']);
        self::assertContains('reproducibility_core', $plan['analysis_modules']);
        self::assertContains('claim_consistency_core', $plan['analysis_modules']);
    }

    public function testModulesAreDeduplicated(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::CrossSectional, ['prevalence'], ['epidemiology'], ['tabular']);

        self::assertSame(array_values(array_unique($plan['analysis_modules'])), $plan['analysis_modules']);
    }

    public function testRouteVersionIsExposed(): void
    {
        $plan = $this->router->buildPlan(StudyDesign::CrossSectional);
        self::assertNotEmpty($plan['route_version']);
    }
}
