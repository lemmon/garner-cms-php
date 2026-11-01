<?php

declare(strict_types=1);

namespace Garner\Core;

use RuntimeException;

/**
 * Thrown from a controller, an action, or a custom route handler to answer
 * with the site's 404 page and a 404 status — for a URL that routes but
 * names something that doesn't exist (an unknown filter value, a missing
 * record). The message is not shown to visitors.
 */
final class NotFoundException extends RuntimeException {}
