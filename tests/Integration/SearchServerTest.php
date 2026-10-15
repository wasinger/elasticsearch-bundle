<?php
namespace Wa72\ElasticsearchBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wa72\ElasticsearchBundle\Command\checkIndexCommand;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;
use Wa72\ElasticsearchBundle\Tests\Fixtures\SearchEngine;
use Wa72\ElasticsearchBundle\Tests\Fixtures\TestKernel;
use Wa72\ESTools\Index;
use Wa72\ESTools\Internal\ClientAdapter;

/**
 * Tests the bundle against a running Elasticsearch or OpenSearch server
 *
 * Select the engine with the environment variable SEARCH_ENGINE and the server with ES_HOST (default: localhost:9200).
 * The tests are skipped if the server is not available, unless the environment variable CI is set.
 * All indices created by these tests start with "wa72_bundle_test_" and are removed before and after each test.
 */
class SearchServerTest extends TestCase
{
    private const PREFIX = 'wa72_bundle_test_';
    private const INDEX = self::PREFIX . 'products';
    private const ALIAS = self::PREFIX . 'products_current';

    private ?TestKernel $kernel = null;
    private ?ClientAdapter $adapter = null;

    protected function setUp(): void
    {
        $engine = SearchEngine::engine();
        if ($engine === null) {
            $this->markTestSkipped('No search client library is installed');
        }
        $this->boot('symfony');
        $this->adapter = new ClientAdapter($this->kernel->getContainer()->get('elasticsearch.client'));
        try {
            $available = $this->adapter->request('ping');
        } catch (\Exception $e) {
            $available = false;
        }
        if (!$available) {
            $message = $engine . ' is not available at ' . SearchEngine::host();
            if (getenv('CI')) {
                // never silently skip tests in CI
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        $this->deleteTestIndices();
    }

    protected function tearDown(): void
    {
        if ($this->adapter) {
            $this->deleteTestIndices();
        }
        $this->kernel?->shutdown();
        $this->kernel = null;
        $this->adapter = null;
    }

    public static function httpClientProvider(): array
    {
        return [
            'symfony' => ['symfony'],
            'guzzle' => ['guzzle'],
        ];
    }

    public function testServerVersion(): void
    {
        $expected = getenv('EXPECTED_SEARCH_VERSION');
        if (!$expected) {
            $this->markTestSkipped('EXPECTED_SEARCH_VERSION is not set');
        }
        $info = $this->adapter->request('info');
        $this->assertSame($expected, $info['version']['number']);
        $this->assertSame(
            SearchEngine::engine() === 'opensearch',
            ($info['version']['distribution'] ?? 'elasticsearch') === 'opensearch'
        );
    }

    #[DataProvider('httpClientProvider')]
    public function testIndexLifecycleWithConsoleCommands(string $httpClient): void
    {
        $this->boot($httpClient);

        // index does not exist yet
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_WARNING, $tester->execute(['index' => self::INDEX]));
        $this->assertStringContainsString('Index does not exist', $this->normalize($tester->getDisplay()));

        // create the index
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_OK, $tester->execute(['index' => self::INDEX, '--create' => true]), $tester->getDisplay());
        $this->assertStringContainsString('Done. Current index: ', $this->normalize($tester->getDisplay()));
        $this->assertTrue($this->adapter->indices('exists', ['index' => self::INDEX]));

        // the index now exists with the configured settings, mappings and aliases
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_OK, $tester->execute(['index' => self::INDEX]), $tester->getDisplay());
        $display = $this->normalize($tester->getDisplay());
        $this->assertStringContainsString('Search hosts: ' . $this->hostUrl(), $display);
        $this->assertStringContainsString('Index exists and all settings are correct', $display);
        $this->assertTrue($this->adapter->indices('exists', ['index' => self::ALIAS]));

        // switching to a non-existing index version fails
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(
            checkIndexCommand::RETURN_ERROR_VERSIONALIAS,
            $tester->execute(['index' => self::INDEX, '--switchalias' => self::PREFIX . 'does_not_exist'])
        );

        // deleting the index must be confirmed
        $tester = $this->command('elasticsearch:deleteindex');
        $tester->setInputs(['no']);
        $this->assertSame(Command::INVALID, $tester->execute(['index' => self::INDEX]));
        $this->assertTrue($this->adapter->indices('exists', ['index' => self::INDEX]));

        $tester = $this->command('elasticsearch:deleteindex');
        $tester->setInputs(['yes']);
        $this->assertSame(Command::SUCCESS, $tester->execute(['index' => self::INDEX]), $tester->getDisplay());
        $this->assertStringContainsString('Index successfully deleted.', $this->normalize($tester->getDisplay()));
        $this->assertFalse($this->adapter->indices('exists', ['index' => self::INDEX]));
    }

    public function testCheckIndexDetectsChangedMappings(): void
    {
        $this->command('elasticsearch:checkindex')->execute(['index' => self::INDEX, '--create' => true]);

        // same index name, but an additional field in the mappings
        $this->boot('symfony', ['properties' => ['price' => ['type' => 'float']]]);
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_WARNING, $tester->execute(['index' => self::INDEX]));
        $this->assertStringContainsString('Missing mappings', $this->normalize($tester->getDisplay()));

        // --create --reindex creates a new index version with the new mappings and switches the aliases to it
        $oldVersion = $this->kernel->getContainer()->get('elasticsearch.index.' . self::INDEX)->getRealIndexName();
        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(
            checkIndexCommand::RETURN_OK,
            $tester->execute(['index' => self::INDEX, '--create' => true, '--reindex' => true]),
            $tester->getDisplay()
        );
        $newVersion = $this->kernel->getContainer()->get('elasticsearch.index.' . self::INDEX)->getRealIndexName();
        $this->assertNotSame($oldVersion, $newVersion);
        $this->assertStringContainsString('Current index: ' . $newVersion, $this->normalize($tester->getDisplay()));

        $tester = $this->command('elasticsearch:checkindex');
        $this->assertSame(checkIndexCommand::RETURN_OK, $tester->execute(['index' => self::INDEX]), $tester->getDisplay());
    }

    #[DataProvider('httpClientProvider')]
    public function testIndexServiceStoresAndFindsDocuments(string $httpClient): void
    {
        $this->boot($httpClient);
        $container = $this->kernel->getContainer();
        /** @var Index $index */
        $index = $container->get('elasticsearch.index.' . self::INDEX);
        $this->assertSame($index, $container->get(IndexRegistry::class)->getIndex(self::INDEX));

        $this->assertNotFalse($index->prepare());
        $index->index(['title' => 'Grüne Äpfel', 'tag' => 'fruit'], '1');
        $index->index(['title' => 'Red tomatoes', 'tag' => 'vegetable'], '2');
        $this->adapter->indices('refresh', ['index' => self::INDEX]);

        $this->assertSame(2, $index->count());
        $this->assertSame('Grüne Äpfel', $index->get('1')['_source']['title']);
        // the custom analyzer from the settings file folds umlauts
        $result = $index->search(['query' => ['match' => ['title' => 'grune apfel']]]);
        $ids = array_map(fn ($hit) => $hit['_id'], $result['hits']['hits']);
        $this->assertSame(['1'], $ids);

        $index->delete('2');
        $this->assertNull($index->get('2'));
    }

    /**
     * Boot a new kernel with the given HTTP client
     *
     * @param array $additionalMappings mappings merged into the mappings from the json file
     */
    private function boot(string $httpClient, array $additionalMappings = []): void
    {
        $this->kernel?->shutdown();
        $this->kernel = new TestKernel([
            'engine' => SearchEngine::engine(),
            'es_server' => SearchEngine::host(),
            'http_client' => $httpClient,
            'indexes' => [
                self::INDEX => [
                    'mappings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_mappings.json',
                    'mappings' => $additionalMappings,
                    'settings_jsonfile' => '@TestBundle/Resources/elasticsearch/products_settings.json',
                    'settings' => ['number_of_replicas' => 0],
                    'aliases' => [self::ALIAS],
                ],
            ],
        ]);
        $this->kernel->boot();
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->kernel))->find($name));
    }

    private function hostUrl(): string
    {
        $host = SearchEngine::host();
        return str_contains($host, '://') ? $host : 'http://' . $host;
    }

    private function deleteTestIndices(): void
    {
        try {
            // resolve concrete index names, so the server need not allow wildcard deletes
            $indices = $this->adapter->indices('get', ['index' => self::PREFIX . '*']);
            if (!empty($indices)) {
                $this->adapter->indices('delete', ['index' => implode(',', array_keys($indices))]);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Remove line breaks and box formatting added by SymfonyStyle
     */
    private function normalize(string $display): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['[OK]', '[INFO]', '[NOTE]', '[ERROR]', '[WARNING]', ' ! '], ' ', $display)));
    }
}
