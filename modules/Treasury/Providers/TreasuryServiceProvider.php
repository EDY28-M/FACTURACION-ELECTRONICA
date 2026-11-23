<?php

namespace Modules\Treasury\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\CashCountService;
use Modules\Treasury\Services\CashFlowService;
use Modules\Treasury\Services\CashTreasuryIntegrationService;
use Modules\Treasury\Services\DetractionService;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\PurchaseFeeService;
use Modules\Treasury\Services\ReconciliationService;
use Modules\Treasury\Services\ReceivablePayableService;
use Modules\Treasury\Services\RetentionPerceptionService;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TransferService;
use Modules\Treasury\Services\TreasuryBalanceService;
use Modules\Treasury\Support\BusinessDayCalculator;
use Modules\Treasury\Support\StatementParser;

class TreasuryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
    }

    public function register()
    {
        $this->app->singleton(TreasuryBalanceService::class, function () {
            return new TreasuryBalanceService();
        });

        $this->app->singleton(TaxRateRepository::class, function () {
            return new TaxRateRepository();
        });

        $this->app->singleton(ItfCalculator::class, function ($app) {
            return new ItfCalculator($app->make(TaxRateRepository::class));
        });

        $this->app->singleton(BancarizationChecker::class, function ($app) {
            return new BancarizationChecker($app->make(TaxRateRepository::class));
        });

        $this->app->singleton(PaymentEvidenceService::class, function ($app) {
            return new PaymentEvidenceService($app->make(BancarizationChecker::class));
        });

        $this->app->singleton(MovementService::class, function ($app) {
            return new MovementService(
                $app->make(TaxRateRepository::class),
                $app->make(ItfCalculator::class),
                $app->make(BancarizationChecker::class),
                $app->make(PaymentEvidenceService::class)
            );
        });

        $this->app->singleton(TransferService::class, function ($app) {
            return new TransferService(
                $app->make(ItfCalculator::class),
                $app->make(PaymentEvidenceService::class)
            );
        });

        $this->app->singleton(CashTreasuryIntegrationService::class, function () {
            return new CashTreasuryIntegrationService();
        });

        $this->app->singleton(PurchaseFeeService::class, function () {
            return new PurchaseFeeService();
        });

        $this->app->singleton(ReceivablePayableService::class, function ($app) {
            return new ReceivablePayableService(
                $app->make(TaxRateRepository::class),
                $app->make(BancarizationChecker::class)
            );
        });

        // Holidays are data-driven (config `treasury.holidays`); rebind this to read them from a table instead.
        $this->app->singleton(BusinessDayCalculator::class, function () {
            return new BusinessDayCalculator();
        });

        $this->app->singleton(DetractionService::class, function ($app) {
            return new DetractionService(
                $app->make(MovementService::class),
                $app->make(BusinessDayCalculator::class),
                $app->make(TaxRateRepository::class)
            );
        });

        $this->app->singleton(RetentionPerceptionService::class, function ($app) {
            return new RetentionPerceptionService($app->make(TaxRateRepository::class));
        });

        $this->app->singleton(StatementParser::class, function () {
            return new StatementParser();
        });

        $this->app->singleton(ReconciliationService::class, function ($app) {
            return new ReconciliationService(
                $app->make(MovementService::class),
                $app->make(TreasuryBalanceService::class)
            );
        });

        $this->app->singleton(CashFlowService::class, function ($app) {
            return new CashFlowService(
                $app->make(TreasuryBalanceService::class),
                $app->make(ReceivablePayableService::class)
            );
        });

        $this->app->singleton(CashBookReport::class, function ($app) {
            return new CashBookReport(
                $app->make(TreasuryBalanceService::class)
            );
        });

        $this->app->singleton(CashCountService::class, function ($app) {
            return new CashCountService(
                $app->make(TreasuryBalanceService::class)
            );
        });

        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('treasury.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php',
            'treasury'
        );
    }

    protected function registerViews()
    {
        $viewPath = resource_path('views/modules/treasury');
        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/treasury';
        }, \Config::get('view.paths')), [$sourcePath]), 'treasury');
    }

    public function provides()
    {
        return [
            TreasuryBalanceService::class,
            TaxRateRepository::class,
            ItfCalculator::class,
            BancarizationChecker::class,
            PaymentEvidenceService::class,
            MovementService::class,
            TransferService::class,
            CashTreasuryIntegrationService::class,
            PurchaseFeeService::class,
            ReceivablePayableService::class,
            CashFlowService::class,
            CashBookReport::class,
            CashCountService::class,
        ];
    }
}
