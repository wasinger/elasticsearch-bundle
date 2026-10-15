<?php
namespace Wa72\ElasticsearchBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Wa72\ElasticsearchBundle\Command\checkIndexCommand;
use Wa72\ElasticsearchBundle\Services\ClientFactory;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;
use Wa72\ElasticsearchBundle\Tests\Fixtures\SearchEngine;
use Wa72\ElasticsearchBundle\Tests\Fixtures\TestKernel;
use Wa72\ESTools\Index;

/**
 * Tests the bundle inside a booted Symfony application, no search server needed
 */
class KernelTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function boot(array $config = [], ?callable $configureContainer = null): TestKernel
    {
        $engine = SearchEngine::engine();
        if ($engine === null) {
            $this->markTestSkipped('No search client library is installed');
        }
        $this->kernel = new TestKernel(['engine' => $engine] + $config, $configureContainer);
        $this->kernel->boot();
        return $this->kernel;
    }

    public function testClientService(): void
    {
        $container = $this->boot(['es_server' => 'es.example.com:9200'])->getContainer();
        $client = $container->get('elasticsearch.client');
        $this->assertInstanceOf(ClientFactory::clientClass(SearchEngine::engine()), $client);
        $this->assertSame(['http://es.example.com:9200'], $container->getParameter('wa72_elasticsearch.es_server'));
    }

    public function testIndexServicesAndRegistry(): void
    {
        $container = $this->boot([
            'indexes' => [
                'products' => [
                    'mappings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_mappings.json',
                    'settings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_settings.json',
                ],
                'articles' => [],
            ],
        ])->getContainer();

        /** @var IndexRegistry $registry */
        $registry = $container->get(IndexRegistry::class);
        $this->assertSame(['products', 'articles'], $registry->list());

        $products = $container->get('elasticsearch.index.products');
        $this->assertInstanceOf(Index::class, $products);
        $this->assertSame('products', $products->getName());
        $this->assertSame($products, $registry->getIndex('products'));
        $this->assertSame($container->get('elasticsearch.client'), $products->getClient());
    }

    public function testCustomClientService(): void
    {
        $engine = SearchEngine::engine();
        $container = $this->boot(
            ['client_service' => 'app.search_client', 'indexes' => ['products' => []]],
            function (ContainerBuilder $container) use ($engine) {
                $container->setDefinition('app.search_client', (new Definition(ClientFactory::clientClass($engine)))
                    ->setFactory([ClientFactory::class, 'create'])
                    ->setArguments([$engine, ['http://custom.example.com:9200']])
                    ->setPublic(true));
            }
        )->getContainer();

        $custom = $container->get('app.search_client');
        $this->assertSame($custom, $container->get('elasticsearch.client'));
        $this->assertSame($custom, $container->get('elasticsearch.index.products')->getClient());
    }

    public function testCommandsAreAvailableInConsole(): void
    {
        $application = new Application($this->boot());
        $this->assertTrue($application->has('elasticsearch:checkindex'));
        $this->assertTrue($application->has('elasticsearch:deleteindex'));
    }

    public function testCheckIndexWithoutIndexName(): void
    {
        $tester = $this->commandTester('elasticsearch:checkindex', ['es_server' => 'es.example.com:9200', 'indexes' => ['products' => [], 'articles' => []]]);
        $this->assertSame(checkIndexCommand::RETURN_OK, $tester->execute([]));
        $display = $this->normalize($tester->getDisplay());
        $this->assertStringContainsString('Search hosts: http://es.example.com:9200', $display);
        $this->assertStringContainsString('Available indices: products, articles', $display);
    }

    public function testCheckIndexWithoutConfiguredIndices(): void
    {
        $tester = $this->commandTester('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_OK, $tester->execute([]));
        $this->assertStringContainsString('No indices configured.', $this->normalize($tester->getDisplay()));
    }

    public function testCheckUnknownIndex(): void
    {
        $tester = $this->commandTester('elasticsearch:checkindex', ['indexes' => ['products' => []]]);
        $this->assertSame(checkIndexCommand::RETURN_ERROR_NO_SUCH_INDEX, $tester->execute(['index' => 'unknown']));
        $this->assertStringContainsString('No index configured with this name: unknown. Available indices: products', $this->normalize($tester->getDisplay()));
    }

    public function testDeleteIndexWithoutIndexName(): void
    {
        $tester = $this->commandTester('elasticsearch:deleteindex', ['indexes' => ['products' => []]]);
        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('Available indices: products', $this->normalize($tester->getDisplay()));
    }

    public function testDeleteUnknownIndex(): void
    {
        $tester = $this->commandTester('elasticsearch:deleteindex');
        $this->assertSame(Command::FAILURE, $tester->execute(['index' => 'unknown']));
        $this->assertStringContainsString('No index configured with this name: unknown. No indices configured.', $this->normalize($tester->getDisplay()));
    }

    private function commandTester(string $name, array $config = []): CommandTester
    {
        $application = new Application($this->boot($config));
        return new CommandTester($application->find($name));
    }

    /**
     * Remove line breaks and box formatting added by SymfonyStyle
     */
    private function normalize(string $display): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['[OK]', '[INFO]', '[NOTE]', '[ERROR]', '[WARNING]', ' ! '], ' ', $display)));
    }
}
