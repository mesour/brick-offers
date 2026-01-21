<?php declare(strict_types = 1);

namespace Tests\Integration;

use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;

final class PresenterTestResult
{
    /**
     * @param array<mixed, mixed>|null $jsonData
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly Response|null $response,
        private readonly array|null $jsonData,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponse(): Response|null
    {
        return $this->response;
    }

    public function isJsonResponse(): bool
    {
        return $this->response instanceof JsonResponse;
    }

    /**
     * @return array<mixed, mixed>|null
     */
    public function getJsonData(): array|null
    {
        return $this->jsonData;
    }

    /**
     * Get a specific key from JSON data
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->jsonData[$key] ?? $default;
    }

    /**
     * Check if response indicates success (2xx status)
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response indicates client error (4xx status)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response indicates server error (5xx status)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Get error code from response
     */
    public function getError(): string|null
    {
        $error = $this->jsonData['error'] ?? null;

        return \is_string($error) ? $error : null;
    }

    /**
     * Get error message from response
     */
    public function getMessage(): string|null
    {
        $message = $this->jsonData['message'] ?? null;

        return \is_string($message) ? $message : null;
    }
}
