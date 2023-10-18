<?php

namespace Codemenco\Gateway\Jibit;

use Codemenco\Gateway\Exceptions\BankException;

class JibitException extends BankException
{
    public static $errors = array(
        0 => 'تراکنش ناموفق میباشد',
    );

    public function __construct($errorId)
    {
        $this->errorId = intval($errorId);

        parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
    }
}
