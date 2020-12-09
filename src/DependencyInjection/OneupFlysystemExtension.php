<?php

declare(strict_types=1);

namespace Oneup\FlysystemBundle\DependencyInjection;

use League\Flysystem\FilesystemInterface;
use Oneup\FlysystemBundle\DependencyInjection\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class OneupFlysystemExtension extends Extension
{
    /** @var array */
    private $adapterFactories;

    /** @var array */
    private $cacheFactories;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('factories.xml');

        [$adapterFactories, $cacheFactories] = $this->getFactories($container);

        $configuration = new Configuration($adapterFactories, $cacheFactories);
        $config = $this->processConfiguration($configuration, $configs);

        $loader->load('adapters.xml');
        $loader->load('flysystem.xml');
        $loader->load('cache.xml');
        $loader->load('plugins.xml');

        $adapters = [];
        $filesystems = [];
        $caches = [];

        foreach ($config['adapters'] as $name => $adapter) {
            $adapters[$name] = $this->createAdapter($name, $adapter, $container, $adapterFactories);
        }

        foreach ($config['cache'] as $name => $cache) {
            $caches[$name] = $this->createCache($name, $cache, $container, $cacheFactories);
        }

        foreach ($config['filesystems'] as $name => $filesystem) {
            $filesystems[$name] = $this->createFilesystem($name, $filesystem, $container, $adapters, $caches);
        }

        $this->loadStreamWrappers($config['filesystems'], $filesystems, $loader, $container);
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('factories.xml');

        [$adapterFactories, $cacheFactories] = $this->getFactories($container);

        return new Configuration($adapterFactories, $cacheFactories);
    }

    private function createCache(string $name, array $config, ContainerBuilder $container, array $factories): string
    {
        foreach ($config as $key => $adapter) {
            if (\array_key_exists($key, $factories)) {
                $id = sprintf('oneup_flysystem.%s_cache', $name);
                $factories[$key]->create($container, $id, $adapter);

                return $id;
            }
        }

        throw new \LogicException(sprintf('The cache \'%s\' is not configured.', $name));
    }

    private function createAdapter(string $name, array $config, ContainerBuilder $container, array $factories): string
    {
        foreach ($config as $key => $adapter) {
            if (\array_key_exists($key, $factories)) {
                $id = sprintf('oneup_flysystem.%s_adapter', $name);
                $factories[$key]->create($container, $id, $adapter);

                return $id;
            }
        }

        throw new \LogicException(sprintf('The adapter \'%s\' is not configured.', $name));
    }

    private function createFilesystem(string $name, array $config, ContainerBuilder $container, array $adapters, array $caches): Reference
    {
        if (!\array_key_exists($config['adapter'], $adapters)) {
            throw new \LogicException(sprintf('The adapter \'%s\' is not defined.', $config['adapter']));
        }

        $adapter = $adapters[$config['adapter']];
        $id = sprintf('oneup_flysystem.%s_filesystem', $name);

        $cache = null;
        if (\array_key_exists($config['cache'], $caches)) {
            $cache = $caches[$config['cache']];

            $container
                ->setDefinition($adapter . '_cached', new ChildDefinition('oneup_flysystem.adapter.cached'))
                ->replaceArgument(0, new Reference($adapter))
                ->replaceArgument(1, new Reference($cache));
        }

        $tagParams = ['key' => $name];

        if ($config['mount']) {
            $tagParams['mount'] = $config['mount'];
        }

        $options = [];

        if (\array_key_exists('visibility', $config)) {
            $options['visibility'] = $config['visibility'];
        }

        if (\array_key_exists('disable_asserts', $config)) {
            $options['disable_asserts'] = $config['disable_asserts'];
        }

        $container
            ->setDefinition($id, new ChildDefinition('oneup_flysystem.filesystem'))
            ->replaceArgument(0, new Reference($cache ? $adapter . '_cached' : $adapter))
            ->replaceArgument(1, $options)
            ->addTag('oneup_flysystem.filesystem', $tagParams)
            ->setPublic(true)
        ;

        if (!empty($config['alias'])) {
            $container->getDefinition($id)->setPublic(false);

            try {
                $alias = $container->setAlias($config['alias'], $id);
            } catch (InvalidArgumentException $exception) {
                $alias = $container->getAlias($config['alias']);
            }

            $alias->setPublic(true);
        }

        // Attach Plugins
        $defFilesystem = $container->getDefinition($id);

        if (isset($config['plugins']) && \is_array($config['plugins'])) {
            foreach ($config['plugins'] as $pluginId) {
                $defFilesystem->addMethodCall('addPlugin', [new Reference($pluginId)]);
            }
        }

        if (method_exists($container, 'registerAliasForArgument')) {
            $aliasName = $name;

            if (!preg_match('~filesystem$~i', $aliasName)) {
                $aliasName .= 'Filesystem';
            }

            $container->registerAliasForArgument($id, FilesystemInterface::class, $aliasName)->setPublic(false);
        }

        return new Reference($id);
    }

    private function getFactories(ContainerBuilder $container): array
    {
        return [
            $this->getAdapterFactories($container),
            $this->getCacheFactories($container),
        ];
    }

    private function getAdapterFactories(ContainerBuilder $container): array
    {
        if (null !== $this->adapterFactories) {
            return $this->adapterFactories;
        }

        $factories = [];
        $services = $container->findTaggedServiceIds('oneup_flysystem.adapter_factory');

        foreach (array_keys($services) as $id) {
            /** @var FactoryInterface $factory */
            $factory = $container->get($id);
            $factories[(string) str_replace('-', '_', $factory->getKey())] = $factory;
        }

        return $this->adapterFactories = $factories;
    }

    private function getCacheFactories(ContainerBuilder $container): array
    {
        if (null !== $this->cacheFactories) {
            return $this->cacheFactories;
        }

        $factories = [];
        $services = $container->findTaggedServiceIds('oneup_flysystem.cache_factory');

        foreach (array_keys($services) as $id) {
            /** @var FactoryInterface $factory */
            $factory = $container->get($id);
            $factories[(string) str_replace('-', '_', $factory->getKey())] = $factory;
        }

        return $this->cacheFactories = $factories;
    }

    /**
     * @param Reference[] $filesystems
     */
    private function loadStreamWrappers(array $configs, array $filesystems, Loader\XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->hasStreamWrapperConfiguration($configs)) {
            return;
        }

        if (!class_exists('Twistor\FlysystemStreamWrapper')) {
            throw new InvalidConfigurationException('twistor/flysystem-stream-wrapper must be installed to use the stream wrapper feature.');
        }

        $loader->load('stream_wrappers.xml');

        $configurations = [];
        foreach ($configs as $name => $filesystem) {
            if (!isset($filesystem['stream_wrapper'])) {
                continue;
            }

            $streamWrapper = array_merge(['configuration' => null], $filesystem['stream_wrapper']);

            $configuration = new ChildDefinition('oneup_flysystem.stream_wrapper.configuration.def');
            $configuration
                ->replaceArgument(0, $streamWrapper['protocol'])
                ->replaceArgument(1, $filesystems[$name])
                ->replaceArgument(2, $streamWrapper['configuration'])
                ->setPublic(false);

            $container->setDefinition('oneup_flysystem.stream_wrapper.configuration.' . $name, $configuration);

            $configurations[$name] = new Reference('oneup_flysystem.stream_wrapper.configuration.' . $name);
        }

        $container->getDefinition('oneup_flysystem.stream_wrapper.manager')->replaceArgument(0, $configurations);
    }

    /**
     * @return bool
     */
    private function hasStreamWrapperConfiguration(array $configs)
    {
        foreach ($configs as $name => $filesystem) {
            if (isset($filesystem['stream_wrapper'])) {
                return true;
            }
        }

        return false;
    }
}