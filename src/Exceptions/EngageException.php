<?php

declare(strict_types=1);

namespace Cbox\Engage\Exceptions;

use RuntimeException;

/** Everything this package throws extends this, so one catch covers Engage as a whole. */
class EngageException extends RuntimeException {}
