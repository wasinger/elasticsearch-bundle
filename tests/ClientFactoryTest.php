<?php
namespace Wa72\ElasticsearchBundle\Tests;

use Elastic\Elasticsearch\Client as ModernClient;
use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use OpenSearch\Client as OpenSearchClient;
use PHPUnit\Framework\TestCase;
use Wa72\ElasticsearchBundle\Services\ClientFactory;

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

    public function testUnknownEngineThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::clientClass('solr');
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

    private function requireClass(string $class): void
    {
        if (!class_exists($class)) {
            $this->markTestSkipped(sprintf('%s is not installed', $class));
        }
    }
}
