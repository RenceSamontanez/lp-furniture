<?php

namespace App\Support;

use RuntimeException;

/** A refused Record Payment; the message is safe to show an admin. */
class PaymentRecordException extends RuntimeException
{
}
