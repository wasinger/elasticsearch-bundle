<?php
namespace Wa72\ElasticsearchBundle\Tests;

use Elastic\Elasticsearch\Client as ModernClient;
use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use OpenSearch\Client as OpenSearchClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Wa72\ElasticsearchBundle\DependencyInjection\Wa72ElasticsearchExtension;
use Wa72\ElasticsearchBundle\Services\ClientFactory;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;

class ExtensionTest extends TestCase
{
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new Wa72ElasticsearchExtension())->load([$config], $container);
        return $container;
    }

    public function testDefaultConfig(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $container = $this->load([]);

        $this->assertSame('elasticsearch', $container->getParameter('wa72_elasticsearch.engine'));
        $this->assertSame('symfony', $container->getParameter('wa72_elasticsearch.http_client'));
        $this->assertSame(['http://localhost:9200'], $container->getParameter('wa72_elasticsearch.es_server'));

        $definition = $container->getDefinition('elasticsearch.client');
        $this->assertSame(ModernClient::class, $definition->getClass());
        $this->assertSame([ClientFactory::class, 'create'], $definition->getFactory());
        $this->assertSame(['elasticsearch', ['http://localhost:9200'], 'symfony'], $definition->getArguments());

        $this->assertTrue($container->hasAlias(ModernClient::class));
        $this->assertSame('elasticsearch.client', (string) $container->getAlias(ModernClient::class));

        $this->assertSame(ModernClientBuilder::class, $container->getDefinition('elasticsearch.clientbuilder')->getClass());
    }

    public function testEsServerAsList(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $container = $this->load(['es_server' => ['es1:9200', 'https://es2:9200']]);
        $this->assertSame(['http://es1:9200', 'https://es2:9200'], $container->getParameter('wa72_elasticsearch.es_server'));
    }

    public function testOpenSearchEngine(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $container = $this->load([
            'engine' => 'opensearch',
            'http_client' => 'guzzle',
            'indexes' => ['products' => ['mappings' => ['properties' => ['name' => ['type' => 'text']]]]],
        ]);

        $definition = $container->getDefinition('elasticsearch.client');
        $this->assertSame(OpenSearchClient::class, $definition->getClass());
        $this->assertSame(['opensearch', ['http://localhost:9200'], 'guzzle'], $definition->getArguments());
        $this->assertSame('elasticsearch.client', (string) $container->getAlias(OpenSearchClient::class));
        $this->assertFalse($container->has('elasticsearch.clientbuilder'));

        $index = $container->getDefinition('elasticsearch.index.products');
        $this->assertEquals(new Reference('elasticsearch.client'), $index->getArgument(0));
    }

    public function testCompiledContainerBuildsServices(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $container = $this->load([
            'engine' => 'opensearch',
            'indexes' => ['products' => []],
        ]);
        $container->getDefinition(IndexRegistry::class)->setPublic(true);
        $container->compile();

        /** @var IndexRegistry $registry */
        $registry = $container->get(IndexRegistry::class);
        $this->assertSame(['products'], $registry->list());
        $this->assertSame(['http://localhost:9200'], $registry->getHosts());
    }

    public function testClientService(): void
    {
        $container = $this->load(['client_service' => 'my.client']);

        $this->assertFalse($container->hasDefinition('elasticsearch.client'));
        $this->assertTrue($container->hasAlias('elasticsearch.client'));
        $this->assertSame('my.client', (string) $container->getAlias('elasticsearch.client'));
        $this->assertFalse($container->has('elasticsearch.clientbuilder'));
    }

    public function testClientServiceIsUsedByIndexRegistry(): void
    {
        $this->requireClass(OpenSearchClient::class);
        $container = $this->load(['client_service' => 'my.client']);
        $container->setDefinition('my.client', (new Definition(OpenSearchClient::class))
            ->setFactory([ClientFactory::class, 'create'])
            ->setArguments(['opensearch', ['http://localhost:9200']]));
        $container->getDefinition(IndexRegistry::class)->setPublic(true);
        $container->compile();

        $this->assertInstanceOf(IndexRegistry::class, $container->get(IndexRegistry::class));
    }

    public function testInvalidEngineIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['engine' => 'solr']);
    }

    public function testInvalidHttpClientIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['http_client' => 'curl']);
    }

    public function testInvalidEsServerIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['es_server' => []]);
    }

    private function requireClass(string $class): void
    {
        if (!class_exists($class)) {
            $this->markTestSkipped(sprintf('%s is not installed', $class));
        }
    }
}
