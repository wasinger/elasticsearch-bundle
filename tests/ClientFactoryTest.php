<?php
namespace Wa72\ElasticsearchBundle\Tests;

use Elastic\Elasticsearch\Client as ModernClient;
use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use Elasticsearch\Client as LegacyClient;
use Elasticsearch\ClientBuilder as LegacyClientBuilder;
use OpenSearch\Client as OpenSearchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wa72\ElasticsearchBundle\Services\ClientFactory;
use Wa72\ElasticsearchBundle\Tests\Fixtures\SearchEngine;

class ClientFactoryTest extends TestCase
{
    public function testNormalizeHostsAddsScheme(): void
    {
        $this->assertSame(['http://localhost:9200'], ClientFactory::normalizeHosts(['localhost:9200']));
    }

    public function testNormalizeHostsKeepsExistingScheme(): void
    {
        $hosts = ['https://es.example.com:9200', 'http://localhost:9200'];
        $this->assertSame($hosts, ClientFactory::normalizeHosts($hosts));
    }

    public static function hostProvider(): array
    {
        return [
            'host only' => ['localhost', 'http://localhost'],
            'host and port' => ['es.example.com:9200', 'http://es.example.com:9200'],
            'ip address' => ['127.0.0.1:9200', 'http://127.0.0.1:9200'],
            'https' => ['https://es.example.com', 'https://es.example.com'],
            'uppercase scheme' => ['HTTPS://es.example.com', 'HTTPS://es.example.com'],
            'with credentials' => ['https://user:pass@es.example.com:9200', 'https://user:pass@es.example.com:9200'],
            'with path' => ['http://proxy.example.com/es', 'http://proxy.example.com/es'],
            'without scheme with path' => ['proxy.example.com/es', 'http://proxy.example.com/es'],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testNormalizeHost(string $host, string $expected): void
    {
        $this->assertSame([$expected], ClientFactory::normalizeHosts([$host]));
    }

    public function testNormalizeHostsReindexesList(): void
    {
        $this->assertSame(
            ['http://es1:9200', 'http://es2:9200'],
            ClientFactory::normalizeHosts([3 => 'es1:9200', 'b' => 'es2:9200'])
        );
    }

    public function testNormalizeHostsWithEmptyList(): void
    {
        $this->assertSame([], ClientFactory::normalizeHosts([]));
    }

    public function testClientClassForElasticsearch(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $this->assertSame(ModernClient::class, ClientFactory::clientClass('elasticsearch'));
    }

    public function testClientClassForOpenSearch(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $this->assertSame(OpenSearchClient::class, ClientFactory::clientClass('opensearch'));
    }

    public function testClientClassForLegacyElasticsearch(): void
    {
        $this->requireLegacyElasticsearch();
        $this->assertSame(LegacyClient::class, ClientFactory::clientClass('elasticsearch'));
        $this->assertSame(LegacyClientBuilder::class, ClientFactory::clientBuilderClass('elasticsearch'));
    }

    public function testClientBuilderClassForElasticsearch(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $this->assertSame(ModernClientBuilder::class, ClientFactory::clientBuilderClass('elasticsearch'));
    }

    public function testClientBuilderClassForOpenSearchIsNull(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $this->assertNull(ClientFactory::clientBuilderClass('opensearch'));
    }

    public function testUnknownEngineThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::clientClass('solr');
    }

    public function testUnknownEngineThrowsOnCreate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('solr');
        ClientFactory::create('solr', ['localhost:9200']);
    }

    public function testMissingOpenSearchLibraryThrows(): void
    {
        if (SearchEngine::openSearchInstalled()) {
            $this->markTestSkipped('opensearch-project/opensearch-php is installed');
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('opensearch-project/opensearch-php');
        ClientFactory::clientClass('opensearch');
    }

    public function testMissingElasticsearchLibraryThrows(): void
    {
        if (SearchEngine::elasticsearchInstalled()) {
            $this->markTestSkipped('elasticsearch/elasticsearch is installed');
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('elasticsearch/elasticsearch');
        ClientFactory::create('elasticsearch', ['localhost:9200']);
    }

    public function testCreateLegacyElasticsearchClient(): void
    {
        $this->requireLegacyElasticsearch();
        $client = ClientFactory::create('elasticsearch', ['localhost:9200', 'https://es2.example.com:9201']);
        $this->assertInstanceOf(LegacyClient::class, $client);
    }

    public function testLegacyElasticsearchClientIgnoresHttpClient(): void
    {
        $this->requireLegacyElasticsearch();
        $this->assertInstanceOf(LegacyClient::class, ClientFactory::create('elasticsearch', ['localhost:9200'], 'guzzle'));
        $this->assertInstanceOf(LegacyClient::class, ClientFactory::create('elasticsearch', ['localhost:9200'], 'symfony'));
    }

    public function testCreateElasticsearchClientWithSymfonyHttpClient(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $client = ClientFactory::create('elasticsearch', ['localhost:9200']);
        $this->assertInstanceOf(ModernClient::class, $client);
        $this->assertInstanceOf(
            \Symfony\Component\HttpClient\Psr18Client::class,
            $client->getTransport()->getClient()
        );
    }

    public function testCreateElasticsearchClientUsesConfiguredHosts(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $client = ClientFactory::create('elasticsearch', ['es1.example.com:9200', 'https://es2.example.com:9201']);
        $pool = $client->getTransport()->getNodePool();
        $nodes = array_map(
            fn ($node) => (string) $node->getUri(),
            (new \ReflectionProperty($pool, 'nodes'))->getValue($pool)
        );
        sort($nodes);
        $this->assertSame(['http://es1.example.com:9200', 'https://es2.example.com:9201'], $nodes);
    }

    public function testCreateElasticsearchClientWithGuzzle(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $this->requireClass(\GuzzleHttp\Client::class);
        $client = ClientFactory::create('elasticsearch', ['localhost:9200'], 'guzzle');
        $this->assertInstanceOf(ModernClient::class, $client);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $client->getTransport()->getClient());
    }

    public function testCreateOpenSearchClientWithSymfonyHttpClient(): void
    {
        $this->requireClass(\OpenSearch\SymfonyClientFactory::class);
        $client = ClientFactory::create('opensearch', ['localhost:9200']);
        $this->assertInstanceOf(OpenSearchClient::class, $client);
    }

    public function testCreateOpenSearchClientWithGuzzle(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $this->requireClass(\GuzzleHttp\Client::class);
        $client = ClientFactory::create('opensearch', ['localhost:9200'], 'guzzle');
        $this->assertInstanceOf(OpenSearchClient::class, $client);
    }

    public function testOpenSearchWithoutHostThrows(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('opensearch', []);
    }

    public function testOpenSearchWithMultipleHostsThrows(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('client_service');
        ClientFactory::create('opensearch', ['localhost:9200', 'localhost:9201']);
    }

    public function testUnknownHttpClientThrows(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('elasticsearch', ['localhost:9200'], 'curl');
    }

    private function requireLegacyElasticsearch(): void
    {
        if (!SearchEngine::legacyElasticsearchInstalled()) {
            $this->markTestSkipped('elasticsearch/elasticsearch 6/7 is not installed');
        }
    }

    private function requireClass(string $class): void
    {
        if (!class_exists($class)) {
            $this->markTestSkipped(sprintf('%s is not installed', $class));
        }
    }
}
