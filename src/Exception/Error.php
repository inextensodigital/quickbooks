<?php

namespace ActiveCollab\Quickbooks\Exception;

class Error
{
    /**
     * @var string
     */
    private $message;

    /**
     * @var string
     */
    private $detail;

    /**
     * @var int
     */
    private $code;

    /**
     * @var mixed
     */
    private $element;

    /**
     * @param string $message
     * @param string $detail
     * @param string $code
     * @param string $element
     */
    public function __construct($message, $detail, $code, $element)
    {
        $this->message = $message;
        $this->detail  = $detail;
        $this->code    = (int) $code;
        $this->element = $element;
    }

    /**
     * @return string
     */
    public function getDetail()
    {
        return $this->detail;
    }

    /**
     * @return int
     */
    public function getCode()
    {
        return $this->code;
    }
}
