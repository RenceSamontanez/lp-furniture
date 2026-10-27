<?php

namespace App\Support;

use RuntimeException;

/** A refused order tracking action; the message is safe to show an admin. */
class OrderTrackingException extends RuntimeException
{
}
