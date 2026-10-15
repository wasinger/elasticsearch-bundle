Wa72\ElasticsearchBundle
========================

Symfony-bundle for working with Elasticsearch or OpenSearch.

Goals and Features:

- provide the [official Elasticsearch PHP Client](https://github.com/elastic/elasticsearch-php) or the [OpenSearch PHP Client](https://github.com/opensearch-project/opensearch-php) as a configurable service in [Symfony](https://github.com/symfony/symfony) projects
- Integrate the [wa72/es-tools](https://github.com/wasinger/es-tools) library
- provide a console command for managing elasticsearch indices (verify mappings and settings, create new index version, set aliases)

Supported search engines
------------------------

Each project uses exactly one search engine. Install the bundle together with the client library for that engine:

| Engine                  | Composer packages                                                                   | Client class                    |
|-------------------------|-------------------------------------------------------------------------------------|---------------------------------|
| Elasticsearch 6.8 / 7.17 | `composer require wa72/elasticsearch-bundle elasticsearch/elasticsearch:"^6.8.4\|^7.17"` | `Elasticsearch\Client`          |
| Elasticsearch 8.19 / 9.x | `composer require wa72/elasticsearch-bundle elasticsearch/elasticsearch:"^8.19\|^9.0"`   | `Elastic\Elasticsearch\Client`  |
| OpenSearch 2.x / 3.x     | `composer require wa72/elasticsearch-bundle opensearch-project/opensearch-php:^2.7`     | `OpenSearch\Client`             |

For Elasticsearch, the bundle detects the installed client generation (6/7 or 8/9) automatically.

Configuration
-------------

```yaml
# config/packages/wa72_elasticsearch.yaml
wa72_elasticsearch:
    # elasticsearch (default) or opensearch
    engine: elasticsearch

    # one host or a list of hosts; "http://" is added if no scheme is given
    es_server: 'http://localhost:9200'

    # PSR-18 HTTP client for OpenSearch and Elasticsearch 8/9: symfony (default) or guzzle
    http_client: symfony

    # optional: id of your own client service, see below
    client_service: ~

    indexes:
        products:
            mappings_jsonfile: '@AppBundle/Resources/elasticsearch/products_mappings.json'
            settings: { number_of_shards: 1 }
            aliases: { products_current: {} }
```

Example for OpenSearch:

```yaml
wa72_elasticsearch:
    engine: opensearch
    es_server: 'https://opensearch.example.com:9200'
```

The OpenSearch client supports only one host. For multi-host setups use the `client_service` option.

### HTTP client

OpenSearch and Elasticsearch 8/9 clients use a PSR-18 HTTP client:

- `http_client: symfony` (default) uses [Symfony HttpClient](https://symfony.com/doc/current/http_client.html). It is installed together with this bundle, so no extra package is needed.
- `http_client: guzzle` uses Guzzle. Install it with `composer require guzzlehttp/guzzle`.

Elasticsearch 6/7 clients always use their built-in Guzzle RingPHP handler, so the setting is ignored there.

### Using your own client service

The bundle only configures the hosts. If you need authentication, SSL settings, a scoped Symfony HTTP client or other transport options,
define the client service yourself and point `client_service` to it:

```yaml
# config/services.yaml
services:
    app.search_client:
        class: Elastic\Elasticsearch\Client
        factory: ['@app.search_client_builder', 'build']
    app.search_client_builder:
        class: Elastic\Elasticsearch\ClientBuilder
        factory: ['Elastic\Elasticsearch\ClientBuilder', 'create']
        calls:
            - setHosts: [['https://es.example.com:9200']]
            - setBasicAuthentication: ['%env(ES_USER)%', '%env(ES_PASSWORD)%']

# config/packages/wa72_elasticsearch.yaml
wa72_elasticsearch:
    client_service: app.search_client
```

In this case the bundle does not build a client, and `elasticsearch.client` is an alias of your service.

Services
--------

- `elasticsearch.client`: the native client (`Elasticsearch\Client`, `Elastic\Elasticsearch\Client` or `OpenSearch\Client`).
  When the bundle builds the client, it is also available for autowiring under its class name.
- `elasticsearch.index.<name>`: a `Wa72\ESTools\Index` service for each configured index.
- `elasticsearch.index_registry` / `Wa72\ElasticsearchBundle\Services\IndexRegistry`: registry of all configured indexes.
- `elasticsearch.clientbuilder`: the Elasticsearch `ClientBuilder` with the configured hosts (Elasticsearch only, not defined when `client_service` is set). Kept for backward compatibility.

Console commands
----------------

- `elasticsearch:checkindex`: check if the index exists and if its settings and mappings are correct
- `elasticsearch:deleteindex`: delete an index

Running the tests
-----------------

```
composer install
vendor/bin/phpunit
```
