<?php

namespace Tests\Unit;

use App\Casts\EncryptedSecret;
use App\Http\Resources\Tenant\ConfigurationEcommerceResource;
use App\Services\Payments\CartPricing;
use App\Services\Payments\CulqiCharger;
use App\Services\Payments\EcommerceCheckout;
use App\Services\Payments\PlatformPayment;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class FakeCulqi
{
    public $calls = [];
    public $Charges;

    public function __construct($response)
    {
        $this->Charges = new class($response, $this) {
            private $response;
            private $parent;
            public function __construct($response, $parent) { $this->response = $response; $this->parent = $parent; }
            public function create($payload)
            {
                $this->parent->calls[] = $payload;
                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }
                return $this->response;
            }
        };
    }
}

class FakeRecord
{
    public $saved = 0;
    public $deleted = false;
    public function __construct(array $attrs = []) { foreach ($attrs as $k => $v) { $this->$k = $v; } }
    public function save() { $this->saved++; }
    public function delete() { $this->deleted = true; }
}

class PaymentsTest extends TestCase
{
    private function approved($cents, $type = 'venta_exitosa', $currency = 'PEN')
    {
        return (object) ['id' => 'chr_test_1', 'amount' => $cents, 'currency_code' => $currency, 'outcome' => (object) ['type' => $type]];
    }

    private function charger(FakeCulqi $fake): CulqiCharger
    {
        return new CulqiCharger(function () use ($fake) { return $fake; });
    }

    public function test_platform_payment_uses_stored_amount_and_marks_paid()
    {
        $fake = new FakeCulqi($this->approved(5000));
        $payment = new FakeRecord(['payment' => '50.00', 'state' => 0, 'reference_payment' => null]);

        $r = (new PlatformPayment($this->charger($fake)))->pay($payment, 'sk', 'tkn', 'a@b.pe', 1);

        $this->assertTrue($r['success']);
        $this->assertSame(5000, $fake->calls[0]['amount']);
        $this->assertSame('PEN', $fake->calls[0]['currency_code']);
        $this->assertSame(1, $payment->state);
        $this->assertSame('culqi:chr_test_1', $payment->reference_payment);
    }

    public function test_platform_payment_already_paid_is_refused_and_double_submit_charges_once()
    {
        $fake = new FakeCulqi($this->approved(5000));
        $service = new PlatformPayment($this->charger($fake));
        $payment = new FakeRecord(['payment' => '50.00', 'state' => 0]);

        $this->assertTrue($service->pay($payment, 'sk', 't1', 'a@b.pe')['success']);
        $this->assertFalse($service->pay($payment, 'sk', 't2', 'a@b.pe')['success']);
        $this->assertCount(1, $fake->calls);
    }

    public function test_review_or_failed_outcome_is_not_paid()
    {
        foreach (['REVIEW', 'rechazada', null] as $type) {
            $fake = new FakeCulqi($this->approved(5000, $type));
            $payment = new FakeRecord(['payment' => '50.00', 'state' => 0]);
            $r = (new PlatformPayment($this->charger($fake)))->pay($payment, 'sk', 't', 'a@b.pe');
            $this->assertFalse($r['success']);
            $this->assertSame(0, $payment->state);
            $this->assertSame(0, $payment->saved);
        }
    }

    public function test_amount_or_currency_mismatch_and_exceptions_are_not_paid_with_generic_message()
    {
        $r = $this->charger(new FakeCulqi($this->approved(1, 'venta_exitosa')))->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['paid']);
        $r = $this->charger(new FakeCulqi($this->approved(5000, 'venta_exitosa', 'USD')))->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['paid']);

        $r = $this->charger(new FakeCulqi(new \RuntimeException('{"secret":"sk_live_leak"}')))->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['paid']);
        $this->assertStringNotContainsString('sk_live_leak', $r['message']);
    }

    private function items()
    {
        return [
            1 => (object) ['id' => 1, 'name' => 'A', 'description' => 'A', 'sale_unit_price' => 118, 'has_igv' => 1, 'igv_general_rate' => 0, 'currency_type_id' => 'PEN'],
            2 => (object) ['id' => 2, 'name' => 'B', 'description' => 'B', 'sale_unit_price' => 100, 'has_igv' => 0, 'igv_general_rate' => 0, 'currency_type_id' => 'USD'],
        ];
    }

    public function test_cart_total_is_recomputed_from_database_prices()
    {
        $igv = function () { return 18.0; };
        $r = CartPricing::total([['id' => 1, 'cantidad' => 2, 'sale_unit_price' => 0.01], ['id' => 2, 'cantidad' => 1]], $this->items(), 3.5, $igv);

        // 2 x 118.00 + (100 * 1.18 USD) * 3.5 = 236 + 413 = 649
        $this->assertSame(649.0, $r['total']);
    }

    public function test_cart_rejects_unknown_items_and_bad_quantities()
    {
        $igv = function () { return 18.0; };
        foreach ([[['id' => 99, 'cantidad' => 1]], [['id' => 1, 'cantidad' => 0]], [['id' => 1, 'cantidad' => -2]], [['id' => 1]], []] as $lines) {
            try {
                CartPricing::total($lines, $this->items(), 1.0, $igv);
                $this->fail('expected rejection');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_order_is_created_before_charge_and_cancelled_on_failure()
    {
        $log = [];
        $fake = new FakeCulqi($this->approved(11800, 'REVIEW'));
        $order = new FakeRecord(['status_order_id' => 1]);
        $r = (new EcommerceCheckout($this->charger($fake)))->pay(function () use (&$log, $order, $fake) {
            $log[] = count($fake->calls); // charges already made when the order is created
            return $order;
        }, 118.00, 'sk', 't', 'a@b.pe');

        $this->assertSame([0], $log);
        $this->assertFalse($r['success']);
        $this->assertTrue($order->deleted);
        $this->assertSame(1, $order->status_order_id);
    }

    public function test_order_marked_paid_after_verified_charge_using_server_total()
    {
        $fake = new FakeCulqi($this->approved(11800));
        $order = new FakeRecord(['status_order_id' => 1]);
        $r = (new EcommerceCheckout($this->charger($fake)))->pay(function () use ($order) { return $order; }, 118.00, 'sk', 't', 'a@b.pe');

        $this->assertTrue($r['success']);
        $this->assertSame(11800, $fake->calls[0]['amount']);
        $this->assertSame(2, $order->status_order_id);
        $this->assertFalse($order->deleted);
    }

    public function test_secret_is_encrypted_round_trip_and_legacy_plain_value_is_tolerated()
    {
        $container = new Container();
        $container->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Facade::setFacadeApplication($container);
        Crypt::clearResolvedInstances();

        $cast = new EncryptedSecret();
        $stored = $cast->set(null, 'k', 'sk_test_123', []);
        $this->assertStringNotContainsString('sk_test_123', $stored);
        $this->assertSame('sk_test_123', $cast->get(null, 'k', $stored, []));
        $this->assertSame('sk_plain_legacy', $cast->get(null, 'k', 'sk_plain_legacy', []));
        $this->assertNull($cast->get(null, 'k', null, []));
        $this->assertNull($cast->set(null, 'k', '', []));
    }

    public function test_ecommerce_resource_never_exposes_the_secret()
    {
        $record = new \App\Models\Tenant\ConfigurationEcommerce();
        $record->setRawAttributes(['id' => 1, 'token_private_culqui' => 'sk_live_secret', 'script_paypal' => '<script>x</script>']);
        $out = (new ConfigurationEcommerceResource($record))->toArray(null);

        $this->assertArrayNotHasKey('token_private_culqui', $out);
        $this->assertArrayNotHasKey('script_paypal', $out);
        $this->assertTrue($out['has_token_private_culqui']);
        $this->assertStringNotContainsString('sk_live_secret', json_encode($out));
    }

    public function test_configuration_models_do_not_mass_assign_paypal_script()
    {
        $this->assertNotContains('script_paypal', (new \App\Models\Tenant\ConfigurationEcommerce())->getFillable());
    }

    private function exception($class, $code = 0)
    {
        return new $class('{"x":1}', $code);
    }

    public function test_unknown_outcomes_are_not_declines_and_keep_records_pending()
    {
        foreach ([[\Culqi\Error\UnableToConnect::class, 0], [\Culqi\Error\UnhandledError::class, 429], [\Culqi\Error\UnhandledError::class, 503], [\RuntimeException::class, 0]] as [$class, $code]) {
            $r = $this->charger(new FakeCulqi($this->exception($class, $code)))->charge('sk', 5000, 'a@b.pe', 'd', 'tkn_abcd');
            $this->assertTrue($r['unknown'], $class.$code);
            $this->assertFalse($r['paid']);
            $this->assertStringContainsString('no vuelva a intentar', $r['message']);
        }

        // real decline
        $r = $this->charger(new FakeCulqi($this->exception(\Culqi\Error\UnhandledError::class, 400)))->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['unknown']);
        $this->assertStringContainsString('rechazado', $r['message']);

        // platform: pending marker, then refused without charging again
        $fake = new FakeCulqi(new \RuntimeException('timeout'));
        $payment = new FakeRecord(['payment' => '50.00', 'state' => 0, 'reference_payment' => null]);
        $service = new PlatformPayment($this->charger($fake));
        $service->pay($payment, 'sk', 't', 'a@b.pe');
        $this->assertSame('culqui:pendiente', $payment->reference_payment);
        $this->assertSame(0, $payment->state);
        $this->assertFalse($service->pay($payment, 'sk', 't2', 'a@b.pe')['success']);
        $this->assertCount(1, $fake->calls);

        // ecommerce: order kept (not deleted) as pending
        $order = new FakeRecord(['status_order_id' => 1]);
        (new EcommerceCheckout($this->charger(new FakeCulqi(new \RuntimeException('timeout')))))->pay(function () use ($order) { return $order; }, 10.0, 'sk', 't', 'a@b.pe');
        $this->assertFalse($order->deleted);
        $this->assertSame('culqui:pendiente', $order->reference_payment);
    }

    public function test_display_math_equals_server_total_for_usd_items_and_no_double_conversion()
    {
        $igv = function () { return 18.0; };
        $items = [2 => $this->items()[2]];
        $cart = CartPricing::total([['id' => 2, 'cantidad' => 3]], $items, 3.5, $igv);

        // Client now uses the server's PEN unit price and never multiplies by the rate again.
        $line = $cart['lines'][0];
        $this->assertSame(413.0, $line['unit_pen']);
        $this->assertSame(round($line['unit_pen'] * 3, 2), $cart['total']);
        $this->assertSame(1239.0, $cart['total']);
    }

    public function test_zero_total_and_fractional_unit_quantities_are_rejected()
    {
        $igv = function () { return 18.0; };
        $free = [1 => (object) ['id' => 1, 'sale_unit_price' => 0, 'has_igv' => 1, 'igv_general_rate' => 0, 'currency_type_id' => 'PEN']];
        foreach ([[$free, [['id' => 1, 'cantidad' => 1]]], [$this->items(), [['id' => 1, 'cantidad' => 1.5]]], [$this->items(), [['id' => 1, 'cantidad' => 0.001]]]] as [$items, $lines]) {
            try {
                CartPricing::total($lines, $items, 1.0, $igv);
                $this->fail('expected rejection');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $kg = [1 => (object) ['id' => 1, 'sale_unit_price' => 10, 'has_igv' => 1, 'igv_general_rate' => 0, 'currency_type_id' => 'PEN', 'unit_type_id' => 'KGM']];
        $this->assertSame(5.0, CartPricing::total([['id' => 1, 'cantidad' => 0.5]], $kg, 1.0, $igv)['total']);
    }

    public function test_undecryptable_payload_returns_null_but_plain_legacy_is_kept()
    {
        $container = new Container();
        $container->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Facade::setFacadeApplication($container);

        $other = new Encrypter(str_repeat('z', 32), 'AES-256-CBC');
        $foreign = $other->encryptString('sk_live_x');
        $cast = new EncryptedSecret();

        $this->assertNull($cast->get(null, 'k', $foreign, []));
        $this->assertSame('sk_plain', $cast->get(null, 'k', 'sk_plain', []));
        $this->assertTrue(EncryptedSecret::looksEncrypted($foreign));
        $this->assertFalse(EncryptedSecret::looksEncrypted('sk_plain'));
    }
}
