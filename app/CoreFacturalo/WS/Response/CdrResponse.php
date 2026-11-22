<?php

namespace App\CoreFacturalo\WS\Response;

/**
 * Class CdrResponse.
 */
class CdrResponse
{
    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var string
     */
    protected $description;

    /**
     * @var string[]
     */
    protected $notes;

    /**
     * @return bool
     */
    public function isAccepted()
    {
        if (!self::isValidCode($this->getCode())) {
            return false;
        }
        $code = intval($this->getCode());

        return $code === 0 || $code >= 4000;
    }

    public static function isValidCode($code): bool
    {
        return (is_int($code) || is_string($code)) && ctype_digit((string) $code);
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     *
     * @return CdrResponse
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @param string $code
     *
     * @return CdrResponse
     */
    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param string $description
     *
     * @return CdrResponse
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * @param string[] $notes
     *
     * @return CdrResponse
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;

        return $this;
    }
}
