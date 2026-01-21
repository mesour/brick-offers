<?php declare(strict_types = 1);

namespace Tests\Integration;

use Nette\Http\IResponse;

/**
 * Mock HTTP response that implements IResponse but doesn't extend final Response class
 */
final class MockHttpResponse implements IResponse
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    public function __construct(private PresenterTester $tester)
    {
    }

    public function setCode(int $code, string|null $reason = null): static
    {
        $this->tester->setResponseCode($code);

        return $this;
    }

    public function getCode(): int
    {
        return $this->tester->getResponseCode();
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function addHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function setContentType(string $type, string|null $charset = null): static
    {
        $this->headers['Content-Type'] = $charset !== null ? "{$type}; charset={$charset}" : $type;

        return $this;
    }

    public function redirect(string $url, int $code = self::S302_Found): void
    {
        $this->setCode($code);
        $this->setHeader('Location', $url);
    }

    public function setExpiration(string|null $expire): static
    {
        return $this;
    }

    public function isSent(): bool
    {
        return false;
    }

    public function getHeader(string $name): string|null
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setCookie(
        string $name,
        string $value,
        string|int|\DateTimeInterface|null $expire,
        string|null $path = null,
        string|null $domain = null,
        bool|null $secure = null,
        bool|null $httpOnly = null,
        string|null $sameSite = null,
    ): static {
        return $this;
    }

    public function deleteCookie(
        string $name,
        string|null $path = null,
        string|null $domain = null,
        bool|null $secure = null,
    ): void {
        // Do nothing
    }

    public function deleteHeader(string $name): static
    {
        unset($this->headers[$name]);

        return $this;
    }
}
