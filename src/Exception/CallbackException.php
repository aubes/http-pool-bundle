<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Exception;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wraps an exception thrown by a then() callback on a successful HTTP response.
 * Distinguishes HTTP errors from application-level errors.
 */
final class CallbackException extends \RuntimeException implements HttpPoolExceptionInterface
{
    public function __construct(
        private readonly ResponseInterface $response,
        \Throwable $previous,
    ) {
        parent::__construct(
            \sprintf('Callback error on successful HTTP response: %s', $previous->getMessage()),
            0,
            $previous,
        );
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
