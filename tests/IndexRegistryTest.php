<?php
namespace Wa72\ElasticsearchBundle\Tests;

use PHPUnit\Framework\TestCase;
use Wa72\ElasticsearchBundle\Services\ClientFactory;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;
use Wa72\ElasticsearchBundle\Tests\Fixtures\SearchEngine;
use Wa72\ESTools\Index;

class IndexRegistryTest extends TestCase
{
    private $client;

    protected function setUp(): void
    {
        $engine = SearchEngine::engine();
        if ($engine === null) {
            $this->markTestSkipped('No search client library is installed');
        }
        // no request is sent to the server in these tests
        $this->client = ClientFactory::create($engine, ['localhost:9200']);
    }

    public function testEmptyRegistry(): void
    {
        $registry = new IndexRegistry($this->client);
        $this->assertSame([], $registry->list());
        $this->assertSame([], $registry->getHosts());
        $this->assertNull($registry->getIndex('products'));
    }

    public function testAddAndGetIndex(): void
    {
        $registry = new IndexRegistry($this->client, ['http://es1:9200', 'http://es2:9200']);
        $products = new Index($this->client, 'products', [], [], []);
        $articles = new Index($this->client, 'articles', [], [], []);
        $registry->addIndex($products);
        $registry->addIndex($articles);

        $this->assertSame(['products', 'articles'], $registry->list());
        $this->assertSame($products, $registry->getIndex('products'));
        $this->assertSame($articles, $registry->getIndex('articles'));
        $this->assertNull($registry->getIndex('unknown'));
        $this->assertSame(['http://es1:9200', 'http://es2:9200'], $registry->getHosts());
    }

    public function testAddingIndexWithSameNameReplacesIt(): void
    {
        $registry = new IndexRegistry($this->client);
        $registry->addIndex(new Index($this->client, 'products', [], [], []));
        $replacement = new Index($this->client, 'products', [], [], []);
        $registry->addIndex($replacement);

        $this->assertSame(['products'], $registry->list());
        $this->assertSame($replacement, $registry->getIndex('products'));
    }
}
