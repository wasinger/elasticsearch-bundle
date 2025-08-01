<?php
namespace Wa72\ElasticsearchBundle\DependencyInjection;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Wa72\ElasticsearchBundle\Command\checkIndexCommand;
use Wa72\ElasticsearchBundle\Command\deleteIndexCommand;
use Wa72\ESTools\Index;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;

class Wa72ElasticsearchExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $es_server = $config['es_server'];
        if (is_scalar($es_server)) $es_server = [$es_server];
        $container->setParameter('wa72_elasticsearch.es_server', $es_server);

        $cb = new Definition(ClientBuilder::class);
        $cb->setFactory([ClientBuilder::class, 'create']);
        $cb->addMethodCall('setHosts', [$container->getParameter('wa72_elasticsearch.es_server')]);
        $container->setDefinition('elasticsearch.clientbuilder', $cb);

        $esc = new Definition(Client::class);
        $esc->setFactory([new Reference('elasticsearch.clientbuilder'), 'build']);
        $container->setDefinition('elasticsearch.client', $esc);

        $ir = new Definition(IndexRegistry::class);
        $ir->addArgument(new Reference('elasticsearch.client'));
        $ir->addArgument($container->getParameter('wa72_elasticsearch.es_server'));

        foreach ($config['indexes'] as $name => $conf) {
            if (!empty($conf['settings_jsonfile'])) {
                $settings = $this->readJsonFile($this->findFile($container, $conf['settings_jsonfile']));
                if (!empty($settings['settings'])) $settings = $settings['settings'];
                $settings = \array_replace_recursive($settings, $conf['settings']);
            } else {
                $settings = $conf['settings'];
            }
            if (!empty($conf['mappings_jsonfile'])) {
                $mappings = $this->readJsonFile($this->findFile($container, $conf['mappings_jsonfile']));
                if (!empty($mappings['mappings'])) $mappings = $mappings['mappings'];
                $mappings = \array_replace_recursive($mappings, $conf['mappings']);
            } else {
                $mappings = $conf['mappings'];
            }
            $definition = new Definition(Index::class);
            $definition
                ->addArgument(new Reference('elasticsearch.client'))
                ->addArgument($name)
                ->addArgument($mappings)
                ->addArgument($settings)
                ->addArgument($conf['aliases']);
            $container->setDefinition(sprintf('elasticsearch.index.%s', $name), $definition);
            $ir->addMethodCall('addIndex', [new Reference(sprintf('elasticsearch.index.%s', $name))]);
        }
        $container->setDefinition(IndexRegistry::class, $ir);
        $container->setAlias('elasticsearch.index_registry', IndexRegistry::class);

        $definition = new Definition(checkIndexCommand::class);
        $definition->addArgument(new Reference(IndexRegistry::class));
        $definition->addTag('console.command');
        $container->setDefinition(checkIndexCommand::class, $definition);

        $definition = new Definition(deleteIndexCommand::class);
        $definition->addArgument(new Reference(IndexRegistry::class));
        $definition->addTag('console.command');
        $container->setDefinition(deleteIndexCommand::class, $definition);
    }

    private function findFile(ContainerBuilder $container, string $file)
    {
        if (substr($file,0, 1) == '@') {
            // Bundle notation
            $wanted = substr($file, 1, strpos($file, \DIRECTORY_SEPARATOR) -1);
            $bundles = $container->getParameter('kernel.bundles');
            foreach ($bundles as $bundleName => $bundleClass) {
                if ($bundleName == $wanted) {
                    $refClass = new \ReflectionClass($bundleClass);
                    $bundleDir = dirname($refClass->getFileName());
                    $filepath = $bundleDir . substr($file, strpos($file, \DIRECTORY_SEPARATOR));
                    if (!\file_exists($filepath)) {
                        throw new \Exception('File ' . $filepath . ' not found');
                    }
                    return $filepath;
                }
            }
            throw new \Exception('File ' . $file . ': Bundle not found');
        }
        throw new \Exception('File ' . $file . ' not found: Finding files outside bundles not yet implemented');
    }

    private function readJsonFile($file)
    {
        $data = json_decode(\file_get_contents($file), true);
        $error = json_last_error();
        if ($error !== \JSON_ERROR_NONE) {
            throw new \Exception('Error parsing json file ' . $file . ': ' . \json_last_error_msg());
        }
        return $data;
    }
}