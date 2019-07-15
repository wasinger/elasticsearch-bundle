<?php
namespace Wa72\ElasticsearchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('wa72_elasticsearch');
        if (method_exists($treeBuilder, 'getRootNode')) {
            $rootNode = $treeBuilder->getRootNode();
        } else {
            // BC layer for symfony/config 4.1 and older
            $rootNode = $treeBuilder->root('wa72_elasticsearch');
        }
        $rootNode
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