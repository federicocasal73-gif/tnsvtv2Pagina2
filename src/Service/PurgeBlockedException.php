<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when a user purge is refused for a business rule (NOT a crash):
 * the caller maps it to 409 with the message.
 *
 * Lives in its own file (PSR-4) so the class is autoloadable wherever it
 * is referenced (controllers, OpenAPI scanner, tests) without having to
 * load UserPurgeService first.
 */
final class PurgeBlockedException extends \RuntimeException {}
