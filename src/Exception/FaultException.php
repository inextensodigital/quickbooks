<?php

namespace ActiveCollab\Quickbooks\Exception;

class FaultException extends \RuntimeException
{
    /**
     * @var array
     */
    private $errors;

    /**
     * {@inheritDoc}
     *
     * @param array $errors
     */
	public function __construct ($message = null, $code = null, $previous = null, $errors = []) {
	    parent::__construct ($message, $code, $previous);
	    $this->errors = [];
	    $this->setErrors($errors);
	}

	private function setErrors($errors)
	{
	    foreach ($errors as $error) {
	        $this->errors[] = new Error(
	            $error['Message'],
	            $error['Detail'],
	            $error['code'],
	            isset($error['element']) ? $error['element'] : null
	        );
	    }
	}

	/**
	 * @return array
	 */
	public function getErrors()
	{
	    return $this->errors;
	}
}
