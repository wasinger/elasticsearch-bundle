<?php
namespace Wa72\ElasticsearchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wa72_elasticsearch');
        $treeBuilder->getRootNode()
            ->children()
                ->enumNode('engine')
                    ->info('The search engine used by this project')
                    ->values(['elasticsearch', 'opensearch'])
                    ->defaultValue('elasticsearch')
                ->end()
                ->variableNode('es_server')
                    ->info('Host URL or list of host URLs of the search server')
                    ->defaultValue('localhost:9200')
                    ->validate()
                        ->ifTrue(function ($v) {
                            if (is_string($v)) return $v === '';
                            if (!is_array($v) || $v === []) return true;
                            foreach ($v as $host) {
                                if (!is_string($host) || $host === '') return true;
                            }
                            return false;
                        })
                        ->thenInvalid('es_server must be a non-empty string or a non-empty list of strings, got %s')
                    ->end()
                ->end()
                ->scalarNode('client_service')
                    ->info('Id of an existing client service to use instead of the client built by this bundle')
                    ->defaultNull()
                ->end()
                ->enumNode('http_client')
                    ->info('PSR-18 HTTP client used by OpenSearch and Elasticsearch 8/9 clients (ignored for Elasticsearch 6/7)')
                    ->values(['symfony', 'guzzle'])
                    ->defaultValue('symfony')
                ->end()
                ->arrayNode('indexes')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('mappings')
                                ->defaultValue([])
                                ->prototype('variable')->end()
                            ->end()
                            ->scalarNode('mappings_jsonfile')->end()
                            ->arrayNode('settings')
                                ->defaultValue([])
                                ->prototype('variable')->end()
                            ->end()
                            ->scalarNode('settings_jsonfile')->end()
                            ->arrayNode('aliases')
                                ->defaultValue([])
                                ->prototype('variable')->end()
                            ->end()
                        ->end() // children
                    ->end() // arrayPrototype
                ->end() // indexes
            ->end() // children
        ;

        return $treeBuilder;
    }
}