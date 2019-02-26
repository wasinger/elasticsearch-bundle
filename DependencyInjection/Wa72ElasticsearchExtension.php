<?php
namespace Wa72\ElasticsearchBundle\DependencyInjection;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Wa72\ElasticsearchBundle\Command\checkIndexCommand;
use Wa72\ElasticsearchBundle\Services\Index;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;

class Wa72ElasticsearchExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $cb = new Definition(ClientBuilder::class);
        $cb->setFactory([ClientBuilder::class, 'create']);
        $cb->addMethodCall('setHosts', [[$config['es_server']]]);
        $container->setDefinition('elasticsearch.clientbuilder', $cb);

        $esc = new Definition(Client::class);
        $esc->setFactory([new Reference('elasticsearch.clientbuilder'), 'build']);
        $container->setDefinition('elasticsearch.client', $esc);

        $ir = new Definition(IndexRegistry::class);
        $ir->addArgument(new Reference('elasticsearch.client'));

        foreach ($config['indexes'] as $name => $conf) {
            // new Index($elasticsearch_client, $name, $mappings, $settings, $aliases)

            if (!empty($conf['settings_jsonfile'])) {
                $settings = $this->readJsonFile($container->get('kernel')->locateResource($conf['settings_jsonfile']));
                $settings = array_replace($settings, $conf['settings']);
            } else {
                $settings = $conf['settings'];
            }
            if (!empty($conf['mappings_jsonfile'])) {
                $mappings = $this->readJsonFile($container->get('kernel')->locateResource($conf['mappings_jsonfile']));
                $mappings = array_replace($mappings, $conf['mappings']);
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
        $container->setDefinition('elasticsearch.index_registry', $ir);

        $definition = new Definition(checkIndexCommand::class);
        $definition->addArgument(new Reference('elasticsearch.index_registry'));
        $definition->addTag('console.command');
        $container->setDefinition(checkIndexCommand::class, $definition);
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