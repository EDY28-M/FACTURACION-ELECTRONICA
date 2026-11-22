<?php

namespace App\CoreFacturalo\WS\Signed\Certificate;

class X509Certificate
{
    /**
     * @var string
     */
    private $pfx;
    /**
     * @var string
     */
    private $password;
    /**
     * @var array
     */
    private $certs;
    /**
     * @var array
     */
    private $subject;

    /**
     * X509Certificate constructor.
     * @param string $pfx
     * @param string $password
     * @throws \Exception
     */
    public function __construct($pfx, $password)
    {
        $this->pfx = $pfx;
        $this->password = $password;
        $this->parsePfx($pfx, $password);
    }

    /**
     * @param string $filename
     * @param string $password
     * @return X509Certificate
     * @throws \Exception
     */
    public static function createFromFile($filename, $password)
    {
        if (!file_exists($filename)) {
            throw new \Exception('Certificate File not found');
        }
        $content = file_get_contents($filename);

        return new X509Certificate($content, $password);
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        return $this->getSubjectValue('name');
    }

    /**
     * @return array|null
     */
    public function getSubject()
    {
        return $this->getSubjectValue('subject');
    }

    /**
     * @return array|null
     */
    public function getIssuer()
    {
        return $this->getSubjectValue('issuer');
    }

    /**
     * Certificate is valid from this date.
     *
     * @return \DateTime|null
     */
    public function getValidFrom()
    {
        $value = $this->getSubjectValue('validFrom_time_t');
        if ($value) {
            return (new \DateTime())->setTimestamp($value);
        }

        return $value;
    }

    /**
     * .
     *
     * @return \DateTime|null
     */
    public function getExpiration()
    {
        $value = $this->getSubjectValue('validTo_time_t');
        if ($value) {
            return (new \DateTime())->setTimestamp($value);
        }

        return $value;
    }

    /**
     * @return array|null
     */
    public function getPurposes()
    {
        return $this->getSubjectValue('purposes');
    }

    /**
     * @return array|null
     */
    public function getExtensions()
    {
        return $this->getSubjectValue('extensions');
    }

    /**
     * Get Public Key.
     *
     * @return string|null
     */
    public function getPublicKey()
    {
        return isset($this->certs['cert']) ? $this->certs['cert'] : null;
    }

    /**
     * Get Private Key.
     *
     * @return string|null
     */
    public function getPrivateKey()
    {
        return isset($this->certs['pkey']) ? $this->certs['pkey'] : null;
    }

    public function getRaw()
    {
        return $this->pfx;
    }

    /**
     * Export the current certificate.
     *
     * @param int $type
     * @return string|null
     */
    public function export($type)
    {
        switch ($type) {
            case X509ContentType::PEM:
                return $this->getPublicKey().$this->getPrivateKey();
            case X509ContentType::CER:
                return $this->getPublicKey();
        }

        return '';
    }

    /**
     * @param $pfx
     * @param $password
     * @throws \Exception
     */
    private function parsePfx($pfx, $password)
    {
        $result = openssl_pkcs12_read($pfx, $certs, $password);

        if ($result === false) {
            $opensslError = openssl_error_string();
            $fallbackCerts = $this->parsePfxViaOpenSslCli($pfx, $password);
            if ($fallbackCerts !== null) {
                $this->certs = $fallbackCerts;
                return;
            }
            throw new \Exception($opensslError ?: 'Unable to read PKCS#12 certificate');
        }

        $this->certs = $certs;
    }

    private function parsePfxViaOpenSslCli($pfx, $password): ?array
    {
        $tmpPfx = tempnam(sys_get_temp_dir(), 'pfx_');
        if ($tmpPfx === false) {
            return null;
        }

        try {
            file_put_contents($tmpPfx, $pfx);
            $process = new \Symfony\Component\Process\Process(
                ['openssl', 'pkcs12', '-legacy', '-in', $tmpPfx, '-nodes', '-passin', 'env:OPENSSL_PFX_PASS'],
                null,
                ['OPENSSL_PFX_PASS' => (string) $password]
            );
            $process->run();
            $pem = $process->getOutput();
        } catch (\Throwable $e) {
            return null;
        } finally {
            @unlink($tmpPfx);
        }

        if (!$pem || strpos($pem, '-----BEGIN CERTIFICATE-----') === false) {
            return null;
        }

        $certMatch = [];
        $keyMatch = [];
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $certMatch);
        preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $pem, $keyMatch);

        if (empty($certMatch[0]) || empty($keyMatch[0])) {
            return null;
        }

        return [
            'cert' => $certMatch[0] . "\n",
            'pkey' => $keyMatch[0] . "\n",
        ];
    }

    private function loadSubject()
    {
        if($this->subject) {
            return;
        }

        $this->subject = openssl_x509_parse($this->getPublicKey());
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    private function getSubjectValue($key)
    {
        $this->loadSubject();

        if (isset($this->subject[$key])) {
            return $this->subject[$key];
        }

        return null;
    }
}