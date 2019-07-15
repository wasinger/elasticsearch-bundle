<?php
namespace Wa72\ElasticsearchBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Wa72\ElasticsearchBundle\DependencyInjection\Compiler\ElasticsearchIndexRegistryPass;

class Wa72ElasticsearchBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new ElasticsearchIndexRegistryPass());
    }
}
