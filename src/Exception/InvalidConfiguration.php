<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Exception;

use InvalidArgumentException;

/**
 * Thrown when generator configuration values are missing or invalid.
 */
final class InvalidConfiguration extends InvalidArgumentException
{
}
