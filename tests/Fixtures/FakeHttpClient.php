<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Fixtures;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Request-capturing PSR-18 client returning pre-staged responses in order,
 * so tests exercise the real SDK code path (URL/header/JSON building) without
 * a network. Injected into the container-bound {@see \BillKit\BillKitClient}.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{status: int, body: array<string, mixed>}> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private readonly Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    /**
     * @param array<string, mixed> $body
     */
    public function stage(int $status, array $body = []): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \RuntimeException('FakeHttpClient: no staged response for ' . $request->getUri());
        }
        $staged = array_shift($this->queue);
        $response = $this->psr17->createResponse($staged['status']);

        return $response->withBody($this->psr17->createStream((string) json_encode($staged['body'])));
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * @return array<string, mixed>
     */
    public function bodyOf(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
