<?php
namespace Wa72\ElasticsearchBundle\Tests;

use Elastic\Elasticsearch\Client as ModernClient;
use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use Elasticsearch\Client as LegacyClient;
use Elasticsearch\ClientBuilder as LegacyClientBuilder;
use OpenSearch\Client as OpenSearchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Wa72\ElasticsearchBundle\Command\checkIndexCommand;
use Wa72\ElasticsearchBundle\Command\deleteIndexCommand;
use Wa72\ElasticsearchBundle\DependencyInjection\Compiler\ElasticsearchIndexRegistryPass;
use Wa72\ElasticsearchBundle\DependencyInjection\Wa72ElasticsearchExtension;
use Wa72\ElasticsearchBundle\Services\ClientFactory;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;
use Wa72\ElasticsearchBundle\Tests\Fixtures\SearchEngine;
use Wa72\ElasticsearchBundle\Tests\Fixtures\TestBundle\TestBundle;
use Wa72\ElasticsearchBundle\Wa72ElasticsearchBundle;
use Wa72\ESTools\Index;

class ExtensionTest extends TestCase
{
    private function load(array ...$configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['TestBundle' => TestBundle::class]);
        (new Wa72ElasticsearchExtension())->load($configs, $container);
        return $container;
    }

    /**
     * Load the extension with the engine of the installed client library
     */
    private function loadWithInstalledEngine(array $config): ContainerBuilder
    {
        $engine = SearchEngine::engine();
        if ($engine === null) {
            $this->markTestSkipped('No search client library is installed');
        }
        return $this->load(['engine' => $engine] + $config);
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

    public function testDefaultConfigWithLegacyElasticsearchClient(): void
    {
        if (!SearchEngine::legacyElasticsearchInstalled()) {
            $this->markTestSkipped('elasticsearch/elasticsearch 6/7 is not installed');
        }
        $container = $this->load([]);

        $definition = $container->getDefinition('elasticsearch.client');
        $this->assertSame(LegacyClient::class, $definition->getClass());
        $this->assertSame([ClientFactory::class, 'create'], $definition->getFactory());
        $this->assertSame('elasticsearch.client', (string) $container->getAlias(LegacyClient::class));

        $builder = $container->getDefinition('elasticsearch.clientbuilder');
        $this->assertSame(LegacyClientBuilder::class, $builder->getClass());
        $this->assertSame([LegacyClientBuilder::class, 'create'], $builder->getFactory());
        $this->assertSame([['setHosts', [['http://localhost:9200']]]], $builder->getMethodCalls());
    }

    public function testClientBuilderGetsHosts(): void
    {
        $this->requireClass(ModernClientBuilder::class);
        $container = $this->load(['es_server' => ['es1:9200', 'es2:9200']]);
        $builder = $container->getDefinition('elasticsearch.clientbuilder');
        $this->assertSame([ModernClientBuilder::class, 'create'], $builder->getFactory());
        $this->assertSame([['setHosts', [['http://es1:9200', 'http://es2:9200']]]], $builder->getMethodCalls());
    }

    public function testMissingEngineLibraryThrows(): void
    {
        if (SearchEngine::openSearchInstalled()) {
            $this->markTestSkipped('opensearch-project/opensearch-php is installed');
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('opensearch-project/opensearch-php');
        $this->load(['engine' => 'opensearch']);
    }

    public function testMissingEngineLibraryIsIgnoredWithClientService(): void
    {
        $container = $this->load(['engine' => 'opensearch', 'client_service' => 'my.client']);
        $this->assertSame('my.client', (string) $container->getAlias('elasticsearch.client'));
        $this->assertSame('opensearch', $container->getParameter('wa72_elasticsearch.engine'));
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

    public function testMultipleConfigsAreMerged(): void
    {
        $container = $this->load(
            ['es_server' => 'es1:9200', 'http_client' => 'guzzle', 'client_service' => 'my.client'],
            ['es_server' => ['https://es2:9200'], 'engine' => 'opensearch']
        );
        $this->assertSame(['https://es2:9200'], $container->getParameter('wa72_elasticsearch.es_server'));
        $this->assertSame('opensearch', $container->getParameter('wa72_elasticsearch.engine'));
        $this->assertSame('guzzle', $container->getParameter('wa72_elasticsearch.http_client'));
    }

    public function testIndexDefinitions(): void
    {
        $container = $this->loadWithInstalledEngine([
            'indexes' => [
                'products' => [
                    'mappings' => ['properties' => ['name' => ['type' => 'text']]],
                    'settings' => ['number_of_shards' => 1],
                    'aliases' => ['products_current'],
                ],
                'articles' => [],
            ],
        ]);

        $products = $container->getDefinition('elasticsearch.index.products');
        $this->assertSame(Index::class, $products->getClass());
        $this->assertEquals([
            new Reference('elasticsearch.client'),
            'products',
            ['properties' => ['name' => ['type' => 'text']]],
            ['number_of_shards' => 1],
            ['products_current'],
        ], $products->getArguments());

        $articles = $container->getDefinition('elasticsearch.index.articles');
        $this->assertEquals([new Reference('elasticsearch.client'), 'articles', [], [], []], $articles->getArguments());
    }

    public function testIndexRegistryDefinition(): void
    {
        $container = $this->loadWithInstalledEngine([
            'es_server' => 'es1:9200',
            'indexes' => ['products' => [], 'articles' => []],
        ]);

        $registry = $container->getDefinition(IndexRegistry::class);
        $this->assertEquals([new Reference('elasticsearch.client'), ['http://es1:9200']], $registry->getArguments());
        $this->assertEquals([
            ['addIndex', [new Reference('elasticsearch.index.products')]],
            ['addIndex', [new Reference('elasticsearch.index.articles')]],
        ], $registry->getMethodCalls());
        $this->assertSame(IndexRegistry::class, (string) $container->getAlias('elasticsearch.index_registry'));
    }

    public function testCommandsAreRegistered(): void
    {
        $container = $this->load(['client_service' => 'my.client']);
        foreach ([checkIndexCommand::class, deleteIndexCommand::class] as $command) {
            $definition = $container->getDefinition($command);
            $this->assertSame($command, $definition->getClass());
            $this->assertTrue($definition->hasTag('console.command'));
            $this->assertEquals([new Reference(IndexRegistry::class)], $definition->getArguments());
        }
    }

    public function testSettingsAndMappingsFromJsonFiles(): void
    {
        $container = $this->loadWithInstalledEngine([
            'indexes' => [
                'products' => [
                    'settings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_settings.json',
                    'settings' => ['number_of_replicas' => 0],
                    'mappings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_mappings.json',
                    'mappings' => ['properties' => ['tag' => ['type' => 'text'], 'price' => ['type' => 'float']]],
                ],
            ],
        ]);
        $index = $container->getDefinition('elasticsearch.index.products');

        // the "settings" and "mappings" wrappers are removed, inline config overrides the files
        $settings = $index->getArgument(3);
        $this->assertSame(1, $settings['number_of_shards']);
        $this->assertSame(0, $settings['number_of_replicas']);
        $this->assertSame('custom', $settings['analysis']['analyzer']['my_analyzer']['type']);

        $this->assertSame([
            'properties' => [
                'title' => ['type' => 'text', 'analyzer' => 'my_analyzer'],
                'tag' => ['type' => 'text'],
                'price' => ['type' => 'float'],
            ],
        ], $index->getArgument(2));
    }

    public function testJsonFileWithoutWrapper(): void
    {
        $container = $this->loadWithInstalledEngine([
            'indexes' => ['products' => ['settings_jsonfile' => '@TestBundle/Resources/elasticsearch/plain_settings.json']],
        ]);
        $this->assertSame(['number_of_shards' => 2], $container->getDefinition('elasticsearch.index.products')->getArgument(3));
    }

    public static function invalidJsonFileProvider(): array
    {
        return [
            'missing file' => ['@TestBundle/Resources/elasticsearch/missing.json', 'not found'],
            'unknown bundle' => ['@UnknownBundle/Resources/elasticsearch/products_settings.json', 'Bundle not found'],
            'file outside bundle' => ['/tmp/products_settings.json', 'outside bundles'],
            'invalid json' => ['@TestBundle/Resources/elasticsearch/invalid.json', 'Error parsing json file'],
        ];
    }

    #[DataProvider('invalidJsonFileProvider')]
    public function testInvalidJsonFileThrows(string $file, string $message): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);
        $this->loadWithInstalledEngine(['indexes' => ['products' => ['mappings_jsonfile' => $file]]]);
    }

    public function testCompilerPassRegistersAdditionalIndexServices(): void
    {
        $container = $this->load(['client_service' => 'my.client', 'indexes' => ['products' => []]]);
        (new Wa72ElasticsearchBundle())->build($container);
        $container->setDefinition('app.custom_index', new Definition(Index::class));

        $pass = null;
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $p) {
            if ($p instanceof ElasticsearchIndexRegistryPass) {
                $pass = $p;
            }
        }
        $this->assertNotNull($pass, 'The bundle registers ElasticsearchIndexRegistryPass');
        $pass->process($container);

        $calls = array_map(
            fn (array $call) => (string) $call[1][0],
            $container->getDefinition(IndexRegistry::class)->getMethodCalls()
        );
        $this->assertContains('app.custom_index', $calls);
        $this->assertContains('elasticsearch.index.products', $calls);
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
