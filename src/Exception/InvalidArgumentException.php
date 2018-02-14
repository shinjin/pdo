<?php
namespace Shinjin\Pdo\Exception;

class InvalidArgumentException extends \InvalidArgumentException
{

    /**
     * List of errors.
     *
     * @var array
     */
    private $errors;

    /**
     * Constructor
     *
     * @param string     $message  Error message
     * @param integer    $code     Error code
     * @param \Exception $previous Previous exception
     * @param array      $errors   Error list
     */
    public function __construct(
        $message,
        $code = 0,
        Exception $previous = null,
        array $errors = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->errors = $errors;
    }

    /**
     * Gets error list.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
