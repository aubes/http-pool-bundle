<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Exception;

final class PoolException extends \RuntimeException implements HttpPoolExceptionInterface
{
    /**
     * @param array<string, \Throwable> $errors
     */
    public function __construct(
        private readonly array $errors,
    ) {
        $keys = implode(', ', array_keys($errors));

        parent::__construct(
            \sprintf('%d request(s) failed during pool execution: %s', \count($errors), $keys),
            0,
            1 === \count($errors) ? reset($errors) : null,
        );
    }

    /**
     * @return array<string, \Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
