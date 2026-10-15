<?php
namespace Wa72\ElasticsearchBundle\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;
use Wa72\ElasticsearchBundle\Tests\Fixtures\TestBundle\TestBundle;
use Wa72\ElasticsearchBundle\Wa72ElasticsearchBundle;

/**
 * Minimal Symfony application using the bundle with the given configuration
 *
 * Every kernel instance uses its own cache directory and container class,
 * so several kernels with different configurations can be booted in one test run.
 */
class TestKernel extends Kernel
{
    private string $id;

    /**
     * @param array $bundleConfig configuration for "wa72_elasticsearch"
     * @param callable|null $configureContainer optional callback receiving the ContainerBuilder, e.g. for defining additional services
     */
    public function __construct(private array $bundleConfig = [], private $configureContainer = null)
    {
        $this->id = bin2hex(random_bytes(6));
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new Wa72ElasticsearchBundle(),
            new TestBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->loadFromExtension('wa72_elasticsearch', $this->bundleConfig);
            if ($this->configureContainer) {
                ($this->configureContainer)($container);
            }
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        // make the bundle's services accessible for assertions
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['elasticsearch.client', IndexRegistry::class, 'elasticsearch.clientbuilder'] as $id) {
                    if ($container->hasDefinition($id)) {
                        $container->getDefinition($id)->setPublic(true);
                    } elseif ($container->hasAlias($id)) {
                        $container->getAlias($id)->setPublic(true);
                    }
                }
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'elasticsearch.index.')) {
                        $definition->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/wa72_elasticsearch_bundle_tests/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/wa72_elasticsearch_bundle_tests/' . $this->id . '/log';
    }

    protected function getContainerClass(): string
    {
        return 'Wa72ElasticsearchBundleTestKernel' . $this->id . 'Container';
    }

    public function shutdown(): void
    {
        parent::shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/wa72_elasticsearch_bundle_tests/' . $this->id);
    }
}
