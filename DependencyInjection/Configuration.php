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
                ->scalarNode('es_server')->defaultValue('localhost:9200')->end()
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