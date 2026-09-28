<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JigarDhulla\SwiggyMcp\Exceptions\TransientException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Posts JSON over any PSR-18 client, discovering one (Guzzle, Symfony, …)
 * when none is given. Network failures surface as {@see TransientException}.
 *
 * @internal
 */
final class HttpClient
{
    private readonly ClientInterface $client;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    public function __construct(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param  array<string, mixed>|null  $body  sent as JSON; null sends no body
     * @param  array<string, string>  $headers
     *
     * @throws TransientException
     */
    public function post(string $url, ?array $body, array $headers = []): ResponseInterface
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        }

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransientException($exception->getMessage(), previous: $exception);
        }
    }
}
