<?php

namespace Tests\Unit;

use App\CoreFacturalo\Facturalo;
use App\CoreFacturalo\WS\Reader\DomCdrReader;
use App\CoreFacturalo\WS\Response\CdrResponse;
use App\CoreFacturalo\WS\Services\BaseSunat;
use App\CoreFacturalo\WS\Zip\ZipFly;
use PHPUnit\Framework\TestCase;

class SunatResponseTest extends TestCase
{
    private function facturalo(string $type = 'invoice'): Facturalo
    {
        $facturalo = new class extends Facturalo {
            public $states = [];
            public $regularizations = [];
            public function __construct() {}
            public function updateState($state_type_id) { $this->states[] = $state_type_id; }
            public function updateRegularizeShipping($code, $description) { $this->regularizations[] = [$code, $description]; }
        };
        $facturalo->setType($type);
        return $facturalo;
    }

    private function invalidCodes(): array
    {
        return ['soap-env:Server', 'soap-env:Client', 'WSDL', '', null, false, true, 0.0, -1, '-0', '+0', ' 0', '0 ', '0x0', '0error', '0.0', '4e3', 'HTTP', 'ERROR_CDR'];
    }

    public function test_only_complete_nonnegative_integer_codes_change_document_state(): void
    {
        foreach ([[0, '05'], ['0', '05'], ['000', '05'], [2335, '09'], ['2335', '09'], [4001, '07'], ['4001', '07']] as [$code, $state]) {
            $facturalo = $this->facturalo();
            $facturalo->validationCodeResponse($code, 'response');
            $this->assertSame([$state], $facturalo->states);
            $this->assertSame([], $facturalo->regularizations);
        }
    }

    public function test_invalid_or_transport_codes_never_mark_invoices_accepted(): void
    {
        foreach ($this->invalidCodes() as $code) {
            $facturalo = $this->facturalo();
            $facturalo->validationCodeResponse($code, 'retry later');
            $this->assertSame([], $facturalo->states, var_export($code, true));
            $this->assertSame([[$code, 'retry later']], $facturalo->regularizations);
        }
    }

    public function test_numeric_exception_still_requires_regularization(): void
    {
        $facturalo = $this->facturalo();
        $facturalo->validationCodeResponse('1033', 'exception');
        $this->assertSame([], $facturalo->states);
        $this->assertSame([['1033', 'exception']], $facturalo->regularizations);
    }

    public function test_dispatch_and_retention_failures_throw_without_accepting(): void
    {
        foreach (['dispatch', 'retention'] as $type) {
            foreach (array_merge($this->invalidCodes(), ['1033']) as $code) {
                $facturalo = $this->facturalo($type);
                $caught = null;
                try {
                    $facturalo->validationCodeResponse($code, 'failure');
                } catch (\Exception $exception) {
                    $caught = $exception;
                }
                $this->assertInstanceOf(\Exception::class, $caught, 'Expected the failed send to throw.');
                $this->assertStringContainsString('Description: failure', $caught->getMessage());
                $this->assertSame([], $facturalo->states);
                $this->assertSame([], $facturalo->regularizations);
            }
        }
    }

    public function test_cdr_acceptance_rejects_invalid_codes_and_preserves_observations(): void
    {
        foreach ($this->invalidCodes() as $code) {
            $this->assertFalse((new CdrResponse())->setCode($code)->isAccepted(), var_export($code, true));
        }
        foreach ([0, '0', '000', 4001, '4001'] as $code) {
            $this->assertTrue((new CdrResponse())->setCode($code)->isAccepted());
        }
        foreach ([1033, '1033', 2335, '2335'] as $code) {
            $this->assertFalse((new CdrResponse())->setCode($code)->isAccepted());
        }
    }

    private function cdr(string $code = '0'): string
    {
        return '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"'
            . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cbc:Note>Observation</cbc:Note><cac:DocumentResponse><cac:Response>'
            . '<cbc:ReferenceID>F001-1</cbc:ReferenceID><cbc:ResponseCode>' . $code . '</cbc:ResponseCode>'
            . '<cbc:Description>Response description</cbc:Description></cac:Response></cac:DocumentResponse></ApplicationResponse>';
    }

    public function test_valid_cdr_keeps_fields_and_notes_regardless_of_namespace_prefix(): void
    {
        foreach ([$this->cdr(), str_replace(['cac:', 'cbc:', ':cac=', ':cbc='], ['aggregate:', 'basic:', ':aggregate=', ':basic='], $this->cdr())] as $xml) {
            $response = (new DomCdrReader())->getCdrResponse($xml);
            $this->assertSame('0', $response->getCode());
            $this->assertSame('F001-1', $response->getId());
            $this->assertSame('Response description', $response->getDescription());
            $this->assertSame(['Observation'], $response->getNotes());
        }
    }

    public function test_missing_response_or_code_throws_type_safe_exception(): void
    {
        foreach (['<empty/>', str_replace('<cbc:ResponseCode>0</cbc:ResponseCode>', '', $this->cdr()), $this->cdr(''), $this->cdr('WSDL'), $this->cdr('0error'), $this->cdr(' 0')] as $xml) {
            $caught = null;
            try {
                (new DomCdrReader())->getCdrResponse($xml);
            } catch (\Exception $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf(\RuntimeException::class, $caught, 'Expected an invalid CDR response to throw.');
            $this->assertIsInt($caught->getCode());
            $this->assertNotEmpty($caught->getMessage());
        }
    }

    public function test_wrong_namespace_uris_cannot_override_registered_cdr_namespaces(): void
    {
        foreach ([
            str_replace('ApplicationResponse-2', 'ApplicationResponse-wrong', $this->cdr()),
            str_replace('CommonAggregateComponents-2', 'CommonAggregateComponents-wrong', $this->cdr()),
            str_replace('CommonBasicComponents-2', 'CommonBasicComponents-wrong', $this->cdr()),
            str_replace('xmlns=', 'xmlns:x="urn:wrong" xmlns=', $this->cdr()),
        ] as $xml) {
            // A shadowing but unused prefix does not change the root's genuine namespace.
            if (strpos($xml, 'xmlns:x=') !== false) {
                $this->assertSame('0', (new DomCdrReader())->getCdrResponse($xml)->getCode());
                continue;
            }
            $caught = null;
            try {
                (new DomCdrReader())->getCdrResponse($xml);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf(\RuntimeException::class, $caught);
        }
    }

    public function test_malformed_xml_throws_without_leaking_parser_warnings_or_changing_libxml_mode(): void
    {
        $previousMode = libxml_use_internal_errors();
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([false, true] as $mode) {
                libxml_use_internal_errors($mode);
                foreach (['', '<ApplicationResponse>', null] as $xml) {
                    $caught = null;
                    try {
                        (new DomCdrReader())->getCdrResponse($xml);
                    } catch (\RuntimeException $exception) {
                        $caught = $exception;
                    }
                    $this->assertInstanceOf(\RuntimeException::class, $caught, 'Expected malformed CDR XML to throw.');
                    $this->assertSame($mode, libxml_use_internal_errors());
                    $this->assertSame([], libxml_get_errors());
                }
            }
        } finally {
            restore_error_handler();
            libxml_clear_errors();
            libxml_use_internal_errors($previousMode);
        }
    }

    public function test_soap_detail_without_message_falls_back_to_fault_string(): void
    {
        $service = new class extends BaseSunat {
            public function __construct() {}
            public function fromFault(\SoapFault $fault) { return $this->getErrorFromFault($fault); }
        };
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([null, new \stdClass(), 'detail text'] as $detail) {
                $error = $service->fromFault(new \SoapFault('soap-env:Server', 'Unauthorized', null, $detail));
                $this->assertSame('soap-env:Server', $error->getCode());
                $this->assertSame('Unauthorized', $error->getMessage());
                $facturalo = $this->facturalo();
                $facturalo->validationCodeResponse($error->getCode(), $error->getMessage());
                $this->assertSame([], $facturalo->states);
                $this->assertSame([['soap-env:Server', 'Unauthorized']], $facturalo->regularizations);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function test_numbers_in_free_text_fault_messages_are_not_sunat_response_codes(): void
    {
        $service = new class extends BaseSunat {
            public function __construct() {}
            public function fromFault(\SoapFault $fault) { return $this->getErrorFromFault($fault); }
        };
        foreach ([
            'Unauthorized for RUC 20123456789',
            'Could not connect on 2026-11-16',
            'HTTP 500 error; attempt 0',
            '4001 - connection failure',
        ] as $message) {
            $error = $service->fromFault(new \SoapFault('soap-env:Server', $message));
            $this->assertSame('soap-env:Server', $error->getCode());
            $this->assertSame($message, $error->getMessage());
            $facturalo = $this->facturalo();
            $facturalo->validationCodeResponse($error->getCode(), $error->getMessage());
            $this->assertSame([], $facturalo->states);
            $this->assertSame([['soap-env:Server', $message]], $facturalo->regularizations);
        }
        foreach (['soap-env:Client.1033', '1033'] as $code) {
            $error = $service->fromFault(new \SoapFault($code, 'Retry the document'));
            $this->assertSame('1033', $error->getCode());
            $facturalo = $this->facturalo();
            $facturalo->validationCodeResponse($error->getCode(), $error->getMessage());
            $this->assertSame([], $facturalo->states);
            $this->assertSame([['1033', 'Retry the document']], $facturalo->regularizations);
        }
    }

    private function sender($result, string $type = 'invoice'): Facturalo
    {
        $facturalo = new class($result) extends Facturalo {
            public $states = [];
            public $regularizations = [];
            public $uploads = [];
            public function __construct($result)
            {
                $this->document = (object) ['filename' => 'fixture-F001-1'];
                $this->company = (object) ['soap_username' => 'fixture', 'soap_password' => 'fixture', 'soap_url' => 'https://sunat.invalid'];
                $this->isOse = true;
                $this->xmlSigned = '<fixture/>';
                $this->wsClient = new class($result) {
                    private $result;
                    public function __construct($result) { $this->result = $result; }
                    public function setCredentials($user, $password) {}
                    public function setService($url) {}
                    public function call($method, $parameters)
                    {
                        if ($this->result instanceof \Throwable) {
                            throw $this->result;
                        }
                        return $this->result;
                    }
                };
            }
            public function updateState($state_type_id) { $this->states[] = $state_type_id; }
            public function updateRegularizeShipping($code, $description) { $this->regularizations[] = [$code, $description]; }
            public function uploadFile($file_content, $file_type) { $this->uploads[] = $file_type; }
        };
        $facturalo->setType($type);
        return $facturalo;
    }

    public function test_actual_failed_send_channel_cannot_accept_even_numeric_or_qualified_fault_codes(): void
    {
        foreach (['invoice', 'retention', 'dispatch'] as $type) {
            foreach ([['0', '0'], ['soap-env:Client.0', '0'], ['soap-env:Client.4001', '4001'], ['1033', '1033'], ['2335', '2335']] as [$faultCode, $expectedCode]) {
                $facturalo = $this->sender(new \SoapFault($faultCode, 'fault, no CDR'), $type);
                $caught = null;
                try {
                    $facturalo->onlySenderXmlSignedBill();
                } catch (\Exception $exception) {
                    $caught = $exception;
                }
                $response = $facturalo->getResponse();
                $this->assertSame($expectedCode, $response['code']);
                $this->assertSame([], $facturalo->uploads);
                if ($expectedCode === '2335') {
                    $this->assertNull($caught);
                    $this->assertSame(['09'], $facturalo->states);
                    $this->assertSame([], $facturalo->regularizations);
                } elseif ($type === 'invoice') {
                    $this->assertNull($caught);
                    $this->assertSame([], $facturalo->states);
                    $this->assertSame([[$expectedCode, $response['description']]], $facturalo->regularizations);
                } else {
                    $this->assertInstanceOf(\Exception::class, $caught);
                    $this->assertSame([], $facturalo->states);
                    $this->assertSame([], $facturalo->regularizations);
                }
            }
        }
    }

    public function test_actual_success_channel_keeps_accepted_and_observed_cdr_states(): void
    {
        foreach ([['0', '05'], ['4001', '07']] as [$code, $state]) {
            $zip = (new ZipFly())->compress('R-fixture.xml', $this->cdr($code));
            $facturalo = $this->sender((object) ['applicationResponse' => $zip]);
            $facturalo->onlySenderXmlSignedBill();
            $this->assertSame([$state], $facturalo->states);
            $this->assertSame([], $facturalo->regularizations);
            $this->assertSame(['cdr'], $facturalo->uploads);
            $this->assertSame($code, $facturalo->getResponse()['code']);
        }
    }
}
