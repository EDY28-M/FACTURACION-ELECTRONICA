<?php

namespace App\CoreFacturalo\WS\Services;

use App\CoreFacturalo\WS\Zip\ZipFileDecompress;
use App\CoreFacturalo\WS\Zip\ZipFly;
use App\CoreFacturalo\WS\Client\WsClient;
use App\CoreFacturalo\WS\Reader\DomCdrReader;
use App\CoreFacturalo\WS\Response\BillResult;
use App\CoreFacturalo\WS\Response\CdrResponse;
use App\CoreFacturalo\WS\Response\Error;
use App\CoreFacturalo\WS\Validator\XmlErrorCodeProvider;

/**
 * Class BaseSunat.
 */
class BaseSunat
{
    /**
     * @var ZipFly
     */
    private $compressor;

    /**
     * @var ZipFileDecompress
     */
    private $decompressor;

    /**
     * @var DomCdrReader
     */
    private $cdrReader;

    /**
     * @var WsClient
     */
    private $client;

    /**
     * @var XmlErrorCodeProvider
     */
    private $codeProvider;

    /**
     * @param XmlErrorCodeProvider $codeProvider
     */
    public function setCodeProvider(XmlErrorCodeProvider $codeProvider)
    {
        $this->codeProvider = $codeProvider;
    }

    /**
     * BaseSunat constructor.
     */
    public function __construct()
    {
        $this->compressor = new ZipFly();
        $this->decompressor = new ZipFileDecompress();
        $this->cdrReader = new DomCdrReader();
    }

    /**
     * @return WsClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param WsClient $client
     *
     * @return BaseSunat
     */
    public function setClient($client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get error from Fault Exception.
     *
     * @param \SoapFault $fault
     *
     * @return Error
     */
    protected function getErrorFromFault(\SoapFault $fault)
    {
        $error = $this->getErrorByCode($fault->faultcode);

        if (empty($error->getMessage())) {
            $error->setMessage($fault->detail->message ?? $fault->faultstring);
        }

        return $error;
    }

    /**
     * @param string $code
     *
     * @return Error
     */
    protected function getErrorByCode($code)
    {
        // Recognize SOAP fault-code suffixes, never numbers embedded in message text.
        if (is_string($code) && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*:)?(?:Client|Server)\.([0-9]+)$/D', $code, $matches)) {
            $code = $matches[1];
        }

        return new Error($code, CdrResponse::isValidCode($code) ? $this->getMessageError($code) : '');
    }

    /**
     * @param string $filename
     * @param string $xml
     *
     * @return string
     */
    protected function compress($filename, $xml)
    {
        return $this->compressor->compress($filename, $xml);
    }

    /**
     * @param $zipContent
     *
     * @return CdrResponse
     */
    protected function extractResponse($zipContent)
    {
        $xml = $this->getXmlResponse($zipContent);

        return $this->cdrReader->getCdrResponse($xml);
    }

    /**
     * @param $code
     *
     * @return string
     */
    protected function getMessageError($code)
    {
        if (empty($this->codeProvider)) {
            return '';
        }

        return $this->codeProvider->getValue($code);
    }

    protected function isExceptionCode($code)
    {
        $value = intval($code);

        return $value >= 100 && $value <= 1999;
    }

    protected function loadErrorByCode(BillResult $result, $code)
    {
        $error = $this->getErrorByCode($code);

        if (empty($error->getMessage()) && $result->getCdrResponse()) {
            $error->setMessage($result->getCdrResponse()->getDescription());
        }

        $result
            ->setSuccess(false)
            ->setError($error);
    }

    private function getXmlResponse($content)
    {
        $filter = function ($filename) {
            return 'xml' === strtolower($this->getFileExtension($filename));
        };
        $files = $this->decompressor->decompress($content, $filter);

        return 0 === count($files) ? '' : $files[0]['content'];
    }

    private function getFileExtension($filename)
    {
        $lastDotPos = strrpos($filename, '.');
        if (!$lastDotPos) {
            return '';
        }

        return substr($filename, $lastDotPos + 1);
    }
}
