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

#[\AllowDynamicProperties]
class FakeRecord
{
    public $saved = 0;
    public $deleted = false;
    public $reference_payment = null;
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

        // 400 = rejected before processing (no charge exists), not unknown
        $r = $this->charger(new FakeCulqi($this->exception(\Culqi\Error\UnhandledError::class, 400)))->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['unknown']);
        $this->assertFalse($r['paid']);
        $this->assertStringContainsString('No se pudo procesar', $r['message']);

        // 402 card_error = declined
        $r = $this->charger(new FakeCulqi($this->exception(\Culqi\Error\UnhandledError::class, 402)))->charge('sk', 5000, 'a@b.pe', 'd', 't');
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
    private function fallbackCharger(array $queue, array &$history): CulqiCharger
    {
        // Guard the missing seam before constructing a charger: RED must never reach real HTTP.
        $parameters = (new \ReflectionMethod(CulqiCharger::class, '__construct'))->getParameters();
        $this->assertCount(2, $parameters);
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($queue));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.culqi.com', 'handler' => $handler,
            'timeout' => 15, 'connect_timeout' => 10, 'http_errors' => false, 'verify' => true,
        ]);
        return new CulqiCharger(null, $client);
    }

    public function test_fallback_sends_expected_charge_contract_and_only_matched_approval_is_paid(): void
    {
        $history = [];
        $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(201, [], json_encode($this->approved(5000)))], $history);
        $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture charge', 'tkn_test_fixture', 3);
        $this->assertSame(['paid' => true, 'unknown' => false, 'needs_3ds' => false, 'id' => 'chr_test_1', 'message' => null], $result);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.culqi.com/v2/charges', (string) $request->getUri());
        $this->assertSame('Bearer sk_test_fixture_only', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame(['amount' => 5000, 'currency_code' => 'PEN', 'email' => 'fixture@example.test', 'description' => 'Fixture charge', 'source_id' => 'tkn_test_fixture', 'installments' => 3], json_decode((string) $request->getBody(), true));
        $this->assertSame(15, $history[0]['options']['timeout']);
        $this->assertSame(10, $history[0]['options']['connect_timeout']);
        $this->assertTrue($history[0]['options']['verify']);
        $this->assertFalse($history[0]['options']['http_errors']);
    }

    public function test_fallback_amount_currency_and_explicit_outcome_checks_are_not_replaced_by_http_success(): void
    {
        foreach ([$this->approved(1), $this->approved(5000, 'venta_exitosa', 'USD'), $this->approved(5000, 'REVIEW'), (object) ['id' => 'chr_test_1', 'amount' => 5000, 'currency_code' => 'PEN']] as $body) {
            $history = [];
            $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(201, [], json_encode($body))], $history);
            $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
            $this->assertFalse($result['paid']);
            $this->assertFalse($result['unknown']);
            $this->assertNull($result['id']);
            $this->assertCount(1, $history);
        }
    }

    public function test_fallback_400_and_422_remain_known_failures_without_exposing_response_body(): void
    {
        foreach ([400, 422] as $status) {
            $history = [];
            $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response($status, [], '{"private_detail":"fixture-provider-detail"}')], $history);
            $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
            $this->assertFalse($result['paid']);
            $this->assertFalse($result['unknown']);
            $this->assertNull($result['id']);
            $this->assertStringNotContainsString('fixture-provider-detail', $result['message']);
            $this->assertCount(1, $history);
        }
    }

    public function test_fallback_rate_limit_server_transport_and_malformed_json_remain_unknown_without_retry(): void
    {
        $responses = [
            new \GuzzleHttp\Psr7\Response(429, [], 'fixture-provider-detail'),
            new \GuzzleHttp\Psr7\Response(503, [], 'fixture-provider-detail'),
            new \GuzzleHttp\Exception\ConnectException('fixture-provider-detail', new \GuzzleHttp\Psr7\Request('POST', 'https://api.culqi.com/v2/charges')),
            new \GuzzleHttp\Psr7\Response(200, [], '{not-json'),
            new \GuzzleHttp\Psr7\Response(201, [], '{not-json'),
        ];
        foreach ($responses as $response) {
            $history = [];
            $charger = $this->fallbackCharger([$response], $history);
            $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
            $this->assertSame(['paid' => false, 'unknown' => true, 'needs_3ds' => false, 'id' => null, 'message' => CulqiCharger::UNKNOWN_MESSAGE], $result);
            $this->assertCount(1, $history);
        }
    }

    public function test_fallback_3ds_challenge_is_never_marked_paid(): void
    {
        $body = ['id' => 'chr_test_challenge', 'amount' => 5000, 'currency_code' => 'PEN', 'outcome' => ['type' => '3ds_challenge'], 'three_ds_action' => 'challenge'];
        $history = [];
        $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(201, [], json_encode($body))], $history);
        $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
        $this->assertFalse($result['paid']);
        $this->assertFalse($result['unknown']);
        $this->assertNull($result['id']);
        $this->assertCount(1, $history);
    }

    public function test_explicit_culqi_factory_has_precedence_over_injected_http_client(): void
    {
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $fake = new FakeCulqi($this->approved(5000));
        $charger = new CulqiCharger(fn () => $fake, new \GuzzleHttp\Client(['handler' => $handler]));
        $result = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
        $this->assertTrue($result['paid']);
        $this->assertCount(1, $fake->calls);
        $this->assertSame([], $history);
    }

    private function cardError(array $extra = []): string
    {
        return json_encode($extra + ['object' => 'error', 'type' => 'card_error', 'charge_id' => 'chr_test_declined', 'decline_code' => 'insufficient_funds', 'merchant_message' => 'declined', 'user_message' => 'La tarjeta no tiene fondos suficientes.']);
    }

    public function test_fallback_402_card_error_is_declined_with_culqi_user_message_and_not_unknown(): void
    {
        $history = [];
        $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(402, [], $this->cardError())], $history);
        $r = $charger->charge('sk_test_fixture_only', 5000, 'fixture@example.test', 'Fixture', 'tkn_test_fixture');
        $this->assertFalse($r['paid']);
        $this->assertFalse($r['unknown']);
        $this->assertFalse($r['needs_3ds']);
        $this->assertSame('La tarjeta no tiene fondos suficientes.', $r['message']);
        $this->assertCount(1, $history);

        // Missing or blank user_message falls back to the generic decline text; tags are stripped and length capped.
        foreach ([$this->cardError(['user_message' => '  ']), '{"type":"card_error"}', 'not-json'] as $body) {
            $history = [];
            $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(402, [], $body)], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
            $this->assertFalse($r['unknown']);
            $this->assertSame(CulqiCharger::DECLINED_MESSAGE, $r['message']);
        }
        $history = [];
        $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(402, [], $this->cardError(['user_message' => '<b>'.str_repeat('x', 400).'</b>']))], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertSame(255, mb_strlen($r['message']));
        $this->assertStringNotContainsString('<', $r['message']);
    }

    public function test_fallback_live_api_400_operacion_denegada_is_declined_with_user_message(): void
    {
        // Shape returned by the Culqi sandbox for the insufficient_funds test card.
        $body = json_encode([
            'object' => 'error', 'type' => 'operacion_denegada', 'charge_id' => 'chr_test_declined',
            'code' => 'DNGE0015', 'decline_code' => 'insufficient_funds',
            'merchant_message' => 'Fondos insuficientes.', 'user_message' => 'Su tarjeta no tiene fondos suficientes.',
        ]);
        $history = [];
        $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(400, [], $body)], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['paid']);
        $this->assertFalse($r['unknown']);
        $this->assertFalse($r['needs_3ds']);
        $this->assertSame('Su tarjeta no tiene fondos suficientes.', $r['message']);
        $this->assertCount(1, $history);

        // A 400 that is not a decline stays a generic rejection and never leaks the provider text.
        $invalid = json_encode(['object' => 'error', 'type' => 'invalid_request_error', 'merchant_message' => 'x', 'user_message' => 'leak']);
        $history = [];
        $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(400, [], $invalid)], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertFalse($r['unknown']);
        $this->assertStringContainsString('No se pudo procesar', $r['message']);
        $this->assertStringNotContainsString('leak', $r['message']);
    }

    public function test_fallback_pre_processing_errors_are_rejected_not_declined_and_not_unknown(): void
    {
        foreach ([400, 401, 403, 404, 405, 422] as $status) {
            $history = [];
            $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response($status, [], '{"type":"x","user_message":"leak"}')], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
            $this->assertFalse($r['paid'], (string) $status);
            $this->assertFalse($r['unknown'], (string) $status);
            $this->assertStringContainsString('No se pudo procesar', $r['message']);
            $this->assertStringNotContainsString('leak', $r['message']);
        }
    }

    public function test_fallback_review_action_code_means_needs_3ds_for_200_and_201(): void
    {
        foreach ([200, 201] as $status) {
            $history = [];
            $body = json_encode(['user_message' => 'El usuario necesita autenticarse', 'action_code' => 'REVIEW']);
            $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response($status, [], $body)], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
            $this->assertSame(['paid' => false, 'unknown' => false, 'needs_3ds' => true, 'id' => null, 'message' => 'El usuario necesita autenticarse'], $r);
        }

        $history = [];
        $r = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(200, [], '{"action_code":"REVIEW"}')], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't');
        $this->assertSame('Su banco requiere autenticación adicional.', $r['message']);
    }

    public function test_fallback_antifraud_and_3ds_are_whitelisted_capped_and_sent(): void
    {
        $history = [];
        $charger = $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(201, [], json_encode($this->approved(5000)))], $history);
        $r = $charger->charge('sk', 5000, 'a@b.pe', 'd', 'tkn_x', null, [], [
            'antifraud' => ['device_finger_print_id' => str_repeat('d', 300), 'first_name' => 'Ana', 'phone_number' => '', 'evil' => 'x', 'last_name' => ['arr']],
            'authentication_3DS' => ['eci' => '05', 'cavv' => 'CAVV', 'protocolVersion' => '2.1.0', 'xid' => null, 'directoryServerTransactionId' => 'ds-1', 'evil' => 'x'],
        ]);
        $this->assertTrue($r['paid']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['device_finger_print_id' => str_repeat('d', 100), 'first_name' => 'Ana'], $body['antifraud_details']);
        $this->assertSame(['eci' => '05', 'cavv' => 'CAVV', 'protocolVersion' => '2.1.0', 'directoryServerTransactionId' => 'ds-1'], $body['authentication_3DS']);

        $history = [];
        $this->fallbackCharger([new \GuzzleHttp\Psr7\Response(201, [], json_encode($this->approved(5000)))], $history)->charge('sk', 5000, 'a@b.pe', 'd', 't', null, [], ['antifraud' => [], 'authentication_3DS' => ['evil' => 'x']]);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('antifraud_details', $body);
        $this->assertArrayNotHasKey('authentication_3DS', $body);
    }

    public function test_platform_needs_3ds_leaves_record_untouched_and_retry_with_3ds_charges_once(): void
    {
        $responses = [
            new \GuzzleHttp\Psr7\Response(200, [], '{"user_message":"auth","action_code":"REVIEW"}'),
            new \GuzzleHttp\Psr7\Response(201, [], json_encode($this->approved(5000))),
        ];
        $history = [];
        $service = new PlatformPayment($this->fallbackCharger($responses, $history));
        $payment = new FakeRecord(['payment' => '50.00', 'state' => 0, 'reference_payment' => null]);

        $first = $service->pay($payment, 'sk', 'tkn', 'a@b.pe', null, ['antifraud' => ['device_finger_print_id' => 'dev1']]);
        $this->assertFalse($first['success']);
        $this->assertTrue($first['needs_3ds']);
        $this->assertSame(0, $payment->saved);
        $this->assertSame(0, $payment->state);
        $this->assertNull($payment->reference_payment);

        $second = $service->pay($payment, 'sk', 'tkn', 'a@b.pe', null, ['antifraud' => ['device_finger_print_id' => 'dev1'], 'authentication_3DS' => ['eci' => '05', 'cavv' => 'c', 'protocolVersion' => '2.1.0']]);
        $this->assertTrue($second['success']);
        $this->assertSame(1, $payment->state);
        $this->assertCount(2, $history);
        $this->assertArrayNotHasKey('authentication_3DS', json_decode((string) $history[0]['request']->getBody(), true));
        $this->assertSame('dev1', json_decode((string) $history[1]['request']->getBody(), true)['antifraud_details']['device_finger_print_id']);

        // Already paid: a third submit never reaches Culqi.
        $this->assertFalse($service->pay($payment, 'sk', 'tkn', 'a@b.pe')['success']);
        $this->assertCount(2, $history);
    }

    public function test_platform_decline_does_not_mark_pending_and_pending_record_is_never_retried(): void
    {
        $history = [];
        $service = new PlatformPayment($this->fallbackCharger([new \GuzzleHttp\Psr7\Response(402, [], $this->cardError())], $history));
        $payment = new FakeRecord(['payment' => '50.00', 'state' => 0, 'reference_payment' => null]);
        $r = $service->pay($payment, 'sk', 't', 'a@b.pe');
        $this->assertFalse($r['success']);
        $this->assertFalse($r['needs_3ds']);
        $this->assertSame('La tarjeta no tiene fondos suficientes.', $r['message']);
        $this->assertNull($payment->reference_payment);

        $pending = new FakeRecord(['payment' => '50.00', 'state' => 0, 'reference_payment' => 'culqui:pendiente']);
        $history = [];
        $r = (new PlatformPayment($this->fallbackCharger([], $history)))->pay($pending, 'sk', 't', 'a@b.pe', null, ['authentication_3DS' => ['eci' => '05']]);
        $this->assertFalse($r['success']);
        $this->assertSame([], $history);
    }

    public function test_ecommerce_needs_3ds_cancels_attempt_without_pending_marker_and_decline_is_cancelled(): void
    {
        $history = [];
        $order = new FakeRecord(['status_order_id' => 1]);
        $r = (new EcommerceCheckout($this->fallbackCharger([new \GuzzleHttp\Psr7\Response(200, [], '{"action_code":"REVIEW"}')], $history)))->pay(function () use ($order) { return $order; }, 50.0, 'sk', 't', 'a@b.pe');
        $this->assertFalse($r['success']);
        $this->assertTrue($r['needs_3ds']);
        $this->assertTrue($order->deleted);
        $this->assertSame(1, $order->status_order_id);
        $this->assertSame('culqui:3ds', $order->reference_payment);

        $history = [];
        $order = new FakeRecord(['status_order_id' => 1]);
        $r = (new EcommerceCheckout($this->fallbackCharger([new \GuzzleHttp\Psr7\Response(402, [], $this->cardError())], $history)))->pay(function () use ($order) { return $order; }, 50.0, 'sk', 't', 'a@b.pe');
        $this->assertFalse($r['needs_3ds']);
        $this->assertTrue($order->deleted);
        $this->assertSame('culqui:rechazado', $order->reference_payment);
        $this->assertSame('La tarjeta no tiene fondos suficientes.', $r['message']);
    }

}
