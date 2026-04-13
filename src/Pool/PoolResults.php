<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

use Symfony\Contracts\HttpClient\ResponseInterface;

final class PoolResults
{
    /**
     * @param array<string, ResponseInterface> $responses
     * @param array<string, \Throwable>        $errors
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $errors = [],
    ) {
    }

    public function get(string $key): ResponseInterface
    {
        if (!isset($this->responses[$key])) {
            throw new \InvalidArgumentException(\sprintf('No response found for key "%s".', $key));
        }

        return $this->responses[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->responses[$key]);
    }

    /**
     * @return array<string, ResponseInterface>
     */
    public function all(): array
    {
        return $this->responses;
    }

    /**
     * @return array<string, \Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return \count($this->errors) > 0;
    }
}
