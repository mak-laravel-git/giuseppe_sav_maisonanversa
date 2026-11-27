<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The account reset refused to run or rolled itself back. The message is shown
 * to the operator as-is.
 */
class AccountResetException extends RuntimeException {}
