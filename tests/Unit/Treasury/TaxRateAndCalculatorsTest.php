<?php

namespace Tests\Unit\Treasury;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

class TaxRateAndCalculatorsTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $this->previousScopes = $scopes->getValue();

        $booted = new \ReflectionProperty(Model::class, 'booted');
        $this->previousBooted = $booted->getValue();

        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->app->instance('config', new Repository());

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');
        $this->db->statement(
            'create table treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)'
        );

        $this->seedTaxRates();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
        }

        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setValue(null, $this->previousScopes);

        $booted = new \ReflectionProperty(Model::class, 'booted');
        $booted->setValue(null, $this->previousBooted);

        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }

        if ($this->previousDispatcher) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    private function seedTaxRates(): void
    {
        $this->db->table('treasury_tax_rates')->insert([
            [
                'tax' => 'itf',
                'code' => null,
                'rate' => 0.00005,
                'amount' => null,
                'min_operation' => null,
                'valid_from' => '2011-04-01',
                'valid_to' => null,
                'legal_reference' => 'Ley 29667 Art. 10 (0.005%)',
            ],
            [
                'tax' => 'bancarization_threshold_pen',
                'code' => null,
                'rate' => null,
                'amount' => 3500.00,
                'min_operation' => null,
                'valid_from' => '2008-01-01',
                'valid_to' => '2022-03-31',
                'legal_reference' => 'TUO Ley 28194 (anterior a DL 1529)',
            ],
            [
                'tax' => 'bancarization_threshold_pen',
                'code' => null,
                'rate' => null,
                'amount' => 2000.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529 Art. 4 (S/ 2,000)',
            ],
            [
                'tax' => 'bancarization_threshold_usd',
                'code' => null,
                'rate' => null,
                'amount' => 500.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529 Art. 4 (US$ 500)',
            ],
        ]);
    }

    public function test_tax_rate_repository_resolves_versioned_rates_by_effective_date(): void
    {
        $repo = new TaxRateRepository();

        $this->assertEqualsWithDelta(0.00005, $repo->getItfRate('2026-11-23'), 0.0000001);
        $this->assertEqualsWithDelta(0.0, $repo->getItfRate('2010-01-01'), 0.0000001);

        $this->assertEqualsWithDelta(3500.00, $repo->getBancarizationThreshold('PEN', '2022-03-31'), 0.001);
        $this->assertEqualsWithDelta(2000.00, $repo->getBancarizationThreshold('PEN', '2022-04-01'), 0.001);
        $this->assertEqualsWithDelta(500.00, $repo->getBancarizationThreshold('USD', '2026-11-23'), 0.001);

        // Foreign currency (EUR) converts PEN threshold using SBS sale rate (2000 / 4.00 = 500.00).
        $this->assertEqualsWithDelta(500.00, $repo->getBancarizationThreshold('EUR', '2026-11-23', 4.00), 0.001);
        $this->assertNull($repo->getBancarizationThreshold('EUR', '2026-11-23', null));
    }

    public function test_itf_calculator_applies_rate_and_honors_exemptions(): void
    {
        $calc = new ItfCalculator(new TaxRateRepository());

        // Bank destination, not exempt, not same-holder: 100,000 * 0.00005 = 5.00
        $standard = $calc->calculate(100000.00, '2026-11-23', true, false, false);
        $this->assertTrue($standard['applies']);
        $this->assertEqualsWithDelta(0.00005, $standard['rate'], 0.0000001);
        $this->assertEqualsWithDelta(5.00, $standard['amount'], 0.0001);
        $this->assertNull($standard['reason']);

        // Small amount rounding: 1,000 * 0.00005 = 0.05
        $small = $calc->calculate(1000.00, '2026-11-23', true, false, false);
        $this->assertTrue($small['applies']);
        $this->assertEqualsWithDelta(0.05, $small['amount'], 0.0001);

        // Cash destination -> exempt
        $cash = $calc->calculate(50000.00, '2026-11-23', false, false, false);
        $this->assertFalse($cash['applies']);
        $this->assertSame('cash_destination', $cash['reason']);
        $this->assertEqualsWithDelta(0.0, $cash['amount'], 0.0001);

        // ITF-exempt account (e.g., BN detracciones) -> exempt
        $bnAccount = $calc->calculate(50000.00, '2026-11-23', true, true, false);
        $this->assertFalse($bnAccount['applies']);
        $this->assertSame('account_itf_exempt', $bnAccount['reason']);

        // Same-holder transfer -> exempt
        $sameHolder = $calc->calculate(50000.00, '2026-11-23', true, false, true);
        $this->assertFalse($sameHolder['applies']);
        $this->assertSame('same_holder_transfer', $sameHolder['reason']);
    }

    public function test_bancarization_checker_evaluates_pen_usd_low_compliance_and_methods(): void
    {
        $checker = new BancarizationChecker(new TaxRateRepository());

        // PEN cash below threshold (1999.99 < 2000)
        $belowPen = $checker->evaluate(1999.99, 'PEN', '2026-11-23', true, '008');
        $this->assertFalse($belowPen['exceeds_threshold']);
        $this->assertFalse($belowPen['requires_exception_or_bank_method']);
        $this->assertSame('008', $belowPen['suggested_sunat_code']);
        $this->assertNull($belowPen['warning']);

        // PEN cash at exact threshold (2000.00 >= 2000) -> requires exception or bank method
        $atPen = $checker->evaluate(2000.00, 'PEN', '2026-11-23', true, '008');
        $this->assertTrue($atPen['exceeds_threshold']);
        $this->assertTrue($atPen['is_cash']);
        $this->assertTrue($atPen['requires_exception_or_bank_method']);
        $this->assertSame('009', $atPen['suggested_sunat_code']);
        $this->assertNotNull($atPen['warning']);

        // USD bank transfer at threshold (500.00 >= 500) -> bank method OK, no exception required
        $usdBank = $checker->evaluate(500.00, 'USD', '2026-11-23', false, '003');
        $this->assertTrue($usdBank['exceeds_threshold']);
        $this->assertFalse($usdBank['is_cash']);
        $this->assertFalse($usdBank['requires_exception_or_bank_method']);
        $this->assertNull($usdBank['warning']);

        // Low-compliance counterparty reduces threshold to 30% (2000 * 0.30 = 600 PEN; 500 * 0.30 = 150 USD)
        $lowCompPen = $checker->evaluate(600.00, 'PEN', '2026-11-23', true, null, true);
        $this->assertEqualsWithDelta(600.00, $lowCompPen['threshold'], 0.001);
        $this->assertTrue($lowCompPen['exceeds_threshold']);
        $this->assertTrue($lowCompPen['requires_exception_or_bank_method']);

        $lowCompUsd = $checker->evaluate(149.99, 'USD', '2026-11-23', true, null, true);
        $this->assertEqualsWithDelta(150.00, $lowCompUsd['threshold'], 0.001);
        $this->assertFalse($lowCompUsd['exceeds_threshold']);
    }
}
