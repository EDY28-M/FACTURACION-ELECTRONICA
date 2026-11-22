<?php

namespace App\CoreFacturalo\WS\Reader;

use App\CoreFacturalo\WS\Response\CdrResponse;

/**
 * Class DomCdrReader.
 */
class DomCdrReader
{
    /**
     * Get Cdr using DomDocument.
     *
     * @param string $xml
     *
     * @return CdrResponse
     *
     * @throws \Exception
     */
    public function getCdrResponse($xml)
    {
        $xpt = $this->getXpath($xml);

        $cdr = $this->getResponseByXpath($xpt);
        if (!$cdr) {
            throw new \RuntimeException('Not found valid cdr response in xml');
        }
        $cdr->setNotes($this->getNotes($xpt));

        return $cdr;
    }

    /**
     * Get Xpath from xml content.
     *
     * @param string $xmlContent
     *
     * @return \DOMXPath
     */
    private function getXpath($xmlContent)
    {
        $doc = new \DOMDocument();
        $previousMode = libxml_use_internal_errors(true);
        try {
            if (!is_string($xmlContent) || $xmlContent === '' || !$doc->loadXML($xmlContent, LIBXML_NONET)) {
                throw new \RuntimeException('Invalid cdr XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousMode);
        }

        $xpt = new \DOMXPath($doc);
        $xpt->registerNamespace('x', 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2');
        $xpt->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpt->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpt;
    }

    /**
     * @param \DOMXPath $xpath
     *
     * @return CdrResponse
     */
    private function getResponseByXpath(\DOMXPath $xpath)
    {
        $resp = $xpath->query('/x:ApplicationResponse/cac:DocumentResponse/cac:Response', null, false);
        if ($resp->length !== 1) {
            return null;
        }
        $obj = $resp[0];

        $cdr = new CdrResponse();
        $cdr->setId($this->getValueByName($obj, 'ReferenceID'))
            ->setCode($this->getValueByName($obj, 'ResponseCode'))
            ->setDescription($this->getValueByName($obj, 'Description'));

        return CdrResponse::isValidCode($cdr->getCode()) ? $cdr : null;
    }

    /**
     * @param \DOMElement $node
     * @param string      $name
     *
     * @return string
     */
    private function getValueByName(\DOMElement $node, $name)
    {
        $values = $node->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', $name);
        if ($values->length !== 1) {
            return '';
        }

        return $values[0]->nodeValue;
    }

    /**
     * @param \DOMXPath $xpath
     *
     * @return string[]
     */
    private function getNotes(\DOMXPath $xpath)
    {
        $nodes = $xpath->query('/x:ApplicationResponse/cbc:Note', null, false);
        $notes = [];
        if ($nodes->length === 0) {
            return $notes;
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $notes[] = $node->nodeValue;
        }

        return $notes;
    }
}
