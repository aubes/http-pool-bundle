<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Exception;

/**
 * Programming error: not catchable by entry catch(), crashes flush() immediately.
 */
final class CircularReferenceException extends \LogicException implements HttpPoolExceptionInterface
{
    public function __construct(string $key)
    {
        parent::__construct(\sprintf(
            'Circular reference detected: addOnce("%s") was called from a callback that was itself triggered by a completed response for this key. Check your then() chains for cycles.',
            $key,
        ));
    }
}
