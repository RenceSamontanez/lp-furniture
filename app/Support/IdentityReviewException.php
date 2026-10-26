<?php

namespace App\Support;

use RuntimeException;

/** A refused identity review action; the message is safe to show to the admin. Code is the HTTP status. */
final class IdentityReviewException extends RuntimeException
{
}
