<?php declare(strict_types = 1);

namespace Tests\Integration;

use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\FileUpload;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;

final class PresenterTester
{
    private int $responseCode = 200;
    private MockHttpResponse $mockResponse;

    public function __construct(private Container $container)
    {
        $this->mockResponse = new MockHttpResponse($this);
    }

    /**
     * Run a presenter action and return the result
     *
     * @param string $presenterName Presenter name (e.g., 'Admin:Api')
     * @param string $action Action name (e.g., 'pageDraftStatus')
     * @param array<string, mixed> $params Request parameters
     * @param string $method HTTP method (GET, POST)
     * @param array<string, mixed>|null $postData POST body data (for JSON endpoints)
     */
    public function runPresenter(
        string $presenterName,
        string $action,
        array $params = [],
        string $method = 'GET',
        array|null $postData = null,
    ): PresenterTestResult {
        return $this->doRunPresenter($presenterName, $action, $params, $method, $postData, []);
    }

    /**
     * Run a presenter action with file uploads
     *
     * @param string $presenterName Presenter name (e.g., 'Admin:Api')
     * @param string $action Action name (e.g., 'fileUpload')
     * @param array<string, mixed> $params Request parameters
     * @param array<string, FileUpload> $files FileUpload objects to include
     */
    public function runPresenterWithFiles(
        string $presenterName,
        string $action,
        array $params,
        array $files,
    ): PresenterTestResult {
        return $this->doRunPresenter($presenterName, $action, $params, 'POST', null, $files);
    }

    /**
     * Create a mock FileUpload object for testing
     *
     * @param string $tmpPath Path to temporary file
     * @param string $name Original filename
     * @param string $mimeType MIME type
     * @param int $size File size
     */
    public function createFileUpload(
        string $tmpPath,
        string $name,
        string $mimeType,
        int $size,
    ): FileUpload {
        return new FileUpload([
            'name' => $name,
            'type' => $mimeType,
            'size' => $size,
            'tmp_name' => $tmpPath,
            'error' => \UPLOAD_ERR_OK,
        ]);
    }

    /**
     * Internal method to run presenter
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $postData
     * @param array<string, FileUpload> $files
     */
    private function doRunPresenter(
        string $presenterName,
        string $action,
        array $params,
        string $method,
        array|null $postData,
        array $files,
    ): PresenterTestResult {
        try {
            // Reset response code
            $this->responseCode = 200;

            // Create HTTP request mock
            $httpRequest = $this->createHttpRequest($method, $params, $postData, $files);

            // Replace HTTP services in container BEFORE creating presenter
            // This way the presenter will get our mocked services via constructor injection
            $this->replaceHttpServices($httpRequest);

            // Create presenter (will get mocked HTTP services)
            $presenterFactory = $this->container->getByType(IPresenterFactory::class);
            $presenter = $presenterFactory->createPresenter($presenterName);

            if (!$presenter instanceof Presenter) {
                throw new \RuntimeException("Failed to create presenter: {$presenterName}");
            }

            // Build request parameters
            $requestParams = \array_merge($params, ['action' => $action]);

            // Create application request
            $request = new Request(
                $presenterName,
                $method,
                $requestParams,
                $method === 'POST' ? $params : [], // POST params
                $files, // files
            );

            // Run presenter
            $response = null;
            try {
                $response = $presenter->run($request);
            } catch (\Nette\Application\AbortException) {
                // AbortException is normal for sendResponse/sendPayload
                $response = $this->extractResponse($presenter);
            }

            return new PresenterTestResult(
                $this->responseCode,
                $response,
                $this->extractJsonData($response),
            );
        } catch (\Throwable $e) {
            throw new \LogicException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Replace HTTP request and response services in the container
     * Uses reflection to bypass type validation in the container
     *
     * @throws \ReflectionException
     */
    private function replaceHttpServices(HttpRequest $httpRequest): void
    {
        // Access the container's internal services array via reflection
        // Use the base Container class since generated containers extend it
        $containerRef = new \ReflectionClass(Container::class);
        $servicesProperty = $containerRef->getProperty('instances');
        $servicesProperty->setAccessible(true);

        /** @var array<string, object> $services */
        $services = $servicesProperty->getValue($this->container);

        // Find and replace http.request service
        $requestServiceName = $this->findServiceName(IRequest::class);

        if ($requestServiceName !== null) {
            $services[$requestServiceName] = $httpRequest;
        }

        // Find and replace http.response service
        $responseServiceName = $this->findServiceName(IResponse::class);

        if ($responseServiceName !== null) {
            $services[$responseServiceName] = $this->mockResponse;
        }

        // Write back the modified services array
        $servicesProperty->setValue($this->container, $services);
    }

    /**
     * Find service name by type in the container
     */
    private function findServiceName(string $type): string|null
    {
        // Try common service names first
        $commonNames = [
            IRequest::class => ['http.request', 'httpRequest', 'nette.httpRequest'],
            IResponse::class => ['http.response', 'httpResponse', 'nette.httpResponse'],
        ];

        foreach ($commonNames[$type] ?? [] as $name) {
            if ($this->container->hasService($name)) {
                return $name;
            }
        }

        // Fallback: search through all services
        foreach ($this->container->findByType($type) as $name) {
            return $name;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $postData
     * @param array<string, FileUpload> $files
     */
    private function createHttpRequest(
        string $method,
        array $params,
        array|null $postData,
        array $files = [],
    ): HttpRequest {
        // Build query string
        $queryString = \http_build_query($params);
        $urlString = 'https://test.local/api/test' . ($queryString !== '' ? '?' . $queryString : '');
        $url = new UrlScript($urlString);

        $rawBody = $postData !== null ? \json_encode($postData) : null;
        $contentType = $files !== [] ? 'multipart/form-data' : 'application/json';

        return new HttpRequest(
            $url,
            $method === 'POST' ? $params : [], // POST params
            $files, // files
            [], // cookies
            [
                'REQUEST_METHOD' => $method,
                'CONTENT_TYPE' => $contentType,
            ],
            $method,
            null,
            null,
            static function() use ($rawBody) {
                return $rawBody;
            },
        );
    }

    public function setResponseCode(int $code): void
    {
        $this->responseCode = $code;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    private function extractResponse(Presenter $presenter): Response|null
    {
        // Try to get response from presenter's payload
        $payload = (array) $presenter->getPayload();

        if ($payload !== []) {
            return new JsonResponse($payload);
        }

        return null;
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function extractJsonData(Response|null $response): array|null
    {
        if ($response instanceof JsonResponse) {
            $payload = $response->getPayload();

            return \is_array($payload) ? $payload : (array) $payload;
        }

        if ($response instanceof TextResponse) {
            $source = $response->getSource();

            if (\is_string($source)) {
                $decoded = \json_decode($source, true);

                return \is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }
}
