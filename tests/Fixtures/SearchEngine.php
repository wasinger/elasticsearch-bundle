<?php
namespace Wa72\ElasticsearchBundle\Tests\Fixtures;

use Elastic\Elasticsearch\ClientBuilder as ModernClientBuilder;
use Elasticsearch\ClientBuilder;
use OpenSearch\Client as OpenSearchClient;

/**
 * Selects the search engine used by tests
 *
 * The engine can be set with the environment variable SEARCH_ENGINE (elasticsearch | opensearch).
 * Otherwise the engine of the installed client library is used (Elasticsearch if both are installed).
 * The server host can be set with the environment variable ES_HOST (default: localhost:9200).
 */
final class SearchEngine
{
    public static function engine(): ?string
    {
        $engine = getenv('SEARCH_ENGINE');
        if ($engine) {
            return $engine;
        }
        if (self::elasticsearchInstalled()) {
            return 'elasticsearch';
        }
        if (self::openSearchInstalled()) {
            return 'opensearch';
        }
        return null;
    }

    public static function host(): string
    {
        return getenv('ES_HOST') ?: 'localhost:9200';
    }

    public static function elasticsearchInstalled(): bool
    {
        return class_exists(ModernClientBuilder::class) || class_exists(ClientBuilder::class);
    }

    public static function modernElasticsearchInstalled(): bool
    {
        return class_exists(ModernClientBuilder::class);
    }

    public static function legacyElasticsearchInstalled(): bool
    {
        return !class_exists(ModernClientBuilder::class) && class_exists(ClientBuilder::class);
    }

    public static function openSearchInstalled(): bool
    {
        return class_exists(OpenSearchClient::class);
    }
}
