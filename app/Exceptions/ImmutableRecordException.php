<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when code tries to update or delete a record that is
 * immutable by design (an accepted agreement, a policy version).
 */
class ImmutableRecordException extends LogicException
{
}
