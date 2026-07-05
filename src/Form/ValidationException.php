<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

namespace App\Form;

/**
 * Thrown by UpdateMapper when a submitted value violates a column's
 * validation_regexp. Carries the column's user-facing validation_message,
 * so page controllers may display getMessage() directly — unlike generic
 * RuntimeExceptions, whose messages must never reach the client.
 */
final class ValidationException extends \RuntimeException
{
}
