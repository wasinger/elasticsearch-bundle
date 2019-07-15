<?php
namespace Wa72\ElasticsearchBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Wa72\ElasticsearchBundle\Services\Index;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;

class ElasticsearchIndexRegistryPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition(IndexRegistry::class);
        foreach ($container->getDefinitions() as $serviceId => $sdef) {
            if ($sdef->getClass() == Index::class) {
                $definition->addMethodCall('addIndex', [new Reference($serviceId)]);
            }
        }
    }
}