<?php
/**
 * Thrown in place of a redirect, so a test can read where it went.
 *
 * @package FoxeLabs\Loggedin\Tests\Support
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Support;

use Exception;

/**
 * Carries the redirect target as its message. Throwing it also stops a
 * handler before its `exit`.
 */
class RedirectException extends Exception {}
