<?php
namespace Wa72\ElasticsearchBundle\Services;

use Elastic\Elasticsearch\Client as ModernClient;
use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use OpenSearch\Client as OpenSearchClient;
use OpenSearch\GuzzleClientFactory;
use OpenSearch\SymfonyClientFactory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the native search client for the configured engine and the installed client library.
 */
final class ClientFactory
{
    public const ENGINE_ELASTICSEARCH = 'elasticsearch';
    public const ENGINE_OPENSEARCH = 'opensearch';

    public const HTTP_CLIENT_SYMFONY = 'symfony';
    public const HTTP_CLIENT_GUZZLE = 'guzzle';

    public static function create(string $engine, array $hosts, string $httpClient = self::HTTP_CLIENT_SYMFONY): Client|ModernClient|OpenSearchClient
    {
        $hosts = self::normalizeHosts($hosts);
        $class = self::clientClass($engine);

        if ($class === OpenSearchClient::class) {
            return self::createOpenSearchClient($hosts, $httpClient);
        }
        if ($class === ModernClient::class) {
            return ModernClientBuilder::create()
                ->setHosts($hosts)
                ->setHttpClient(self::createPsr18Client($httpClient))
                ->build();
        }
        // Elasticsearch 6/7 always use Guzzle RingPHP, the http_client setting is ignored
        return ClientBuilder::create()->setHosts($hosts)->build();
    }

    /**
     * Get the class name of the native client for the given engine
     *
     * @throws \LogicException if the client library for the engine is not installed
     */
    public static function clientClass(string $engine): string
    {
        return match ($engine) {
            self::ENGINE_OPENSEARCH => class_exists(OpenSearchClient::class)
                ? OpenSearchClient::class
                : throw new \LogicException('The OpenSearch client library is not installed. Run "composer require opensearch-project/opensearch-php:^2.7".'),
            self::ENGINE_ELASTICSEARCH => match (true) {
                class_exists(ModernClientBuilder::class) => ModernClient::class,
                class_exists(ClientBuilder::class) => Client::class,
                default => throw new \LogicException('The Elasticsearch client library is not installed. Run "composer require elasticsearch/elasticsearch".'),
            },
            default => throw new \InvalidArgumentException(sprintf('Unknown search engine "%s", expected "%s" or "%s".', $engine, self::ENGINE_ELASTICSEARCH, self::ENGINE_OPENSEARCH)),
        };
    }

    /**
     * Get the class name of the client builder for the given engine, or null if there is none
     */
    public static function clientBuilderClass(string $engine): ?string
    {
        return match (self::clientClass($engine)) {
            ModernClient::class => ModernClientBuilder::class,
            Client::class => ClientBuilder::class,
            default => null,
        };
    }

    /**
     * Add the http:// scheme to hosts that have none
     *
     * @param string[] $hosts
     * @return string[]
     */
    public static function normalizeHosts(array $hosts): array
    {
        return array_values(array_map(
            fn (string $host) => preg_match('#^[a-z][a-z0-9+.-]*://#i', $host) ? $host : 'http://' . $host,
            $hosts
        ));
    }

    private static function createOpenSearchClient(array $hosts, string $httpClient): OpenSearchClient
    {
        if (count($hosts) !== 1) {
            throw new \InvalidArgumentException(sprintf('The OpenSearch client supports exactly one host, %d given. Use the "client_service" option for multi-host setups.', count($hosts)));
        }
        $options = ['base_uri' => $hosts[0]];

        if ($httpClient === self::HTTP_CLIENT_GUZZLE) {
            self::assertGuzzleInstalled();
            return (new GuzzleClientFactory())->create($options);
        }
        self::assertHttpClient($httpClient);
        if (!class_exists(SymfonyClientFactory::class)) {
            throw new \LogicException('OpenSearch\SymfonyClientFactory not found. Run "composer require opensearch-project/opensearch-php:^2.7".');
        }
        self::assertSymfonyHttpClientInstalled();
        return (new SymfonyClientFactory())->create($options);
    }

    private static function createPsr18Client(string $httpClient): ClientInterface
    {
        if ($httpClient === self::HTTP_CLIENT_GUZZLE) {
            self::assertGuzzleInstalled();
            return new \GuzzleHttp\Client();
        }
        self::assertHttpClient($httpClient);
        self::assertSymfonyHttpClientInstalled();
        return new Psr18Client(HttpClient::create());
    }

    private static function assertHttpClient(string $httpClient): void
    {
        if ($httpClient !== self::HTTP_CLIENT_SYMFONY) {
            throw new \InvalidArgumentException(sprintf('Unknown HTTP client "%s", expected "%s" or "%s".', $httpClient, self::HTTP_CLIENT_SYMFONY, self::HTTP_CLIENT_GUZZLE));
        }
    }

    private static function assertGuzzleInstalled(): void
    {
        if (!class_exists(\GuzzleHttp\Client::class)) {
            throw new \LogicException('Guzzle is not installed. Run "composer require guzzlehttp/guzzle" or set "http_client: symfony".');
        }
    }

    private static function assertSymfonyHttpClientInstalled(): void
    {
        if (!class_exists(Psr18Client::class)) {
            throw new \LogicException('Symfony HttpClient is not installed. Run "composer require symfony/http-client nyholm/psr7" or set "http_client: guzzle".');
        }
    }
}
