<?php

declare(strict_types=1);

namespace Oneup\FlysystemBundle\Tests\DependencyInjection;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\MountManager;
use Oneup\FlysystemBundle\DependencyInjection\OneupFlysystemExtension;
use Oneup\FlysystemBundle\StreamWrapper\StreamWrapperManager;
use Oneup\FlysystemBundle\Tests\Model\ContainerAwareTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OneupFlysystemExtensionTest extends ContainerAwareTestCase
{
    public function testIfTestSuiteLoads(): void
    {
        self::assertTrue(true);
    }

    public function testVisibilitySettings(): void
    {
//        self::markTestSkipped('Does not work on Travis.org servers.');

        /**
         * No visibility flag set.
         *
         * @var Filesystem $filesystem1
         */
        $filesystem1 = self::$container->get('oneup_flysystem.myfilesystem_filesystem');

        /**
         * Visibility flag is set to "public".
         *
         * @var Filesystem $filesystem2
         */
        $filesystem2 = self::$container->get('oneup_flysystem.myfilesystem2_filesystem');

        /**
         * Visibility flag ist set to "private".
         *
         * @var Filesystem $filesystem3
         */
        $filesystem3 = self::$container->get('oneup_flysystem.myfilesystem3_filesystem');

        $filesystem1->write('1/meep', 'meep\'s content');
        $filesystem2->write('2/meep', 'meep\'s content');
        $filesystem3->write('3/meep', 'meep\'s content');

        self::assertSame(AdapterInterface::VISIBILITY_PUBLIC, $filesystem1->getVisibility('1/meep'));
        self::assertSame(AdapterInterface::VISIBILITY_PUBLIC, $filesystem2->getVisibility('2/meep'));
        self::assertSame(AdapterInterface::VISIBILITY_PRIVATE, $filesystem3->getVisibility('3/meep'));

        $filesystem1->delete('1/meep');
        $filesystem1->delete('2/meep');
        $filesystem1->delete('3/meep');
    }

    public function testDisableAssertsSetting(): void
    {
        /**
         * Enabled asserts.
         *
         * @var Filesystem $filesystem1
         */
        $filesystem1 = self::$container->get('oneup_flysystem.myfilesystem_filesystem');

        /**
         * Disabled asserts.
         *
         * @var Filesystem $filesystem2
         */
        $filesystem2 = self::$container->get('oneup_flysystem.myfilesystem2_filesystem');

        self::assertFalse($filesystem1->getConfig()->get('disable_asserts'));
        self::assertTrue($filesystem2->getConfig()->get('disable_asserts'));
    }

    public function testIfMountManagerIsFilled(): void
    {
        /** @var MountManager $mountManager */
        $mountManager = self::$container->get('oneup_flysystem.mount_manager');

        self::assertInstanceOf('League\Flysystem\Filesystem', $mountManager->getFilesystem('prefix'));
    }

    public function testIfOnlyConfiguredFilesystemsAreMounted(): void
    {
        $this->expectException(\LogicException::class);

        /** @var MountManager $mountManager */
        $mountManager = self::$container->get('oneup_flysystem.mount_manager');

        self::assertInstanceOf('League\Flysystem\Filesystem', $mountManager->getFilesystem('prefix2'));
        self::assertInstanceOf('League\Flysystem\Filesystem', $mountManager->getFilesystem('unrelated'));
    }

    public function testAdapterAvailability(): void
    {
        /** @var \SimpleXMLElement $adapters */
        $adapters = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../src/Resources/config/adapters.xml'));

        foreach ($adapters->children()->children() as $service) {
            foreach ($service->attributes() as $key => $attribute) {
                // skip awss3v2 test - it's still BETA
                if ('id' === (string) $key && 'oneup_flysystem.adapter.awss3v3' === (string) $attribute) {
                    break;
                }

                if ('class' === (string) $key) {
                    self::assertTrue(class_exists((string) $attribute), 'Could not load class: ' . (string) $attribute);
                }
            }
        }
    }

    /**
     * Checks if a filesystem with configured cached is from type CachedAdapter.
     */
    public function testIfCachedAdapterAreCached(): void
    {
        /** @var Filesystem $filesystem */
        $filesystem = self::$container->get('oneup_flysystem.myfilesystem_filesystem');
        $adapter = $filesystem->getAdapter();

        self::assertInstanceOf('League\Flysystem\Cached\CachedAdapter', $adapter);
    }

    public function testGetConfiguration(): void
    {
        $extension = new OneupFlysystemExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf('Oneup\FlysystemBundle\DependencyInjection\Configuration', $configuration);
    }

    public function testIfNoStreamWrappersConfiguration(): void
    {
        $container = $this->loadExtension([]);

        self::assertFalse($container->hasDefinition('oneup_flysystem.stream_wrapper.manager'));
    }

    /**
     * @dataProvider provideDefectiveStreamWrapperConfigurations
     */
    public function testIfDefectiveStreamWrapperConfiguration(array $streamWrapperConfig): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->loadExtension([
            'oneup_flysystem' => [
                'adapters' => [
                    'myadapter' => ['local' => ['directory' => '/path/to/mount-point']],
                ],
                'filesystems' => [
                    'myfilesystem' => [
                        'adapter' => 'myadapter',
                        'stream_wrapper' => $streamWrapperConfig,
                    ],
                ],
            ],
        ]);
    }

    public function provideDefectiveStreamWrapperConfigurations(): array
    {
        $config = [
            'permissions' => [
                'dir' => [
                    'private' => 0700,
                    'public' => 0744,
                ],
                'file' => [
                    'private' => 0700,
                    'public' => 0744,
                ],
            ],
            'metadata' => ['visibility'],
            'public_mask' => 0044,
        ];

        return [
            // empty configuration
            [['protocol' => 'myadapter', 'configuration' => null]],
            [['protocol' => 'myadapter', 'configuration' => []]],
            // missing permissions
            [['protocol' => 'myadapter', 'configuration' => array_merge($config, ['permissions' => null])]],
            // missing metadata
            [['protocol' => 'myadapter', 'configuration' => array_merge($config, ['metadata' => null])]],
            [['protocol' => 'myadapter', 'configuration' => array_merge($config, ['metadata' => []])]],
            // missing public mask
            [['protocol' => 'myadapter', 'configuration' => array_merge($config, ['public_mask' => null])]],
        ];
    }

    /**
     * @dataProvider provideStreamWrapperConfigurationTests
     *
     * @param string|array $streamWrapperConfig
     */
    public function testStreamWrapperConfiguration(string $protocol, array $configuration = null, $streamWrapperConfig): void
    {
        $container = $this->loadExtension([
            'oneup_flysystem' => [
                'adapters' => [
                    'myadapter' => ['local' => ['directory' => '/path/to/mount-point']],
                ],
                'filesystems' => [
                    'myfilesystem' => [
                        'adapter' => 'myadapter',
                        'stream_wrapper' => $streamWrapperConfig,
                    ],
                ],
            ],
        ]);

        $definition = $container->getDefinition('oneup_flysystem.stream_wrapper.configuration.myfilesystem');
        self::assertSame($protocol, $definition->getArgument(0));
        self::assertSame($configuration, $definition->getArgument(2));
    }

    public function provideStreamWrapperConfigurationTests(): array
    {
        $config = [
            'permissions' => [
                'dir' => [
                    'private' => 0700,
                    'public' => 0744,
                ],
                'file' => [
                    'private' => 0700,
                    'public' => 0744,
                ],
            ],
            'metadata' => ['visibility'],
            'public_mask' => 0044,
        ];

        return [
            ['myfilesystem', null, 'myfilesystem'],
            ['myfilesystem', null, ['protocol' => 'myfilesystem']],
            ['myfilesystem', $config, ['protocol' => 'myfilesystem', 'configuration' => $config]],
        ];
    }

    public function testStreamWrapperSettings(): void
    {
        /** @var StreamWrapperManager $manager */
        $manager = self::$container->get('oneup_flysystem.stream_wrapper.manager');

        self::assertTrue($manager->hasConfiguration('myfilesystem'));
        self::assertInstanceOf('Oneup\FlysystemBundle\StreamWrapper\Configuration', $configuration = $manager->getConfiguration('myfilesystem'));
        self::assertFalse($manager->hasConfiguration('myfilesystem2'));
        self::assertFalse($manager->hasConfiguration('myfilesystem3'));
    }

    public function testServiceAliasWithFilesystemSuffix(): void
    {
        if (!method_exists(ContainerBuilder::class, 'registerAliasForArgument')) {
            self::markTestSkipped('Symfony 4.2 needed to test container alias registration for arguments.');
        }

        $container = $this->loadExtension([
            'oneup_flysystem' => [
                'adapters' => [
                    'default_adapter' => [
                        'local' => [
                            'directory' => '.',
                        ],
                    ],
                ],
                'filesystems' => [
                    'acme_filesystem' => [
                        'alias' => Filesystem::class,
                        'adapter' => 'default_adapter',
                    ],
                ],
            ],
        ]);

        $aliasName = 'League\Flysystem\FilesystemInterface $acmeFilesystem';

        self::assertTrue($container->hasAlias($aliasName));
        self::assertSame('oneup_flysystem.acme_filesystem_filesystem', (string) $container->getAlias($aliasName));
    }

    public function testServiceAliasWithoutFilesystemSuffix(): void
    {
        if (!method_exists(ContainerBuilder::class, 'registerAliasForArgument')) {
            self::markTestSkipped('Symfony 4.2 needed to test container alias registration for arguments.');
        }

        $container = $this->loadExtension([
            'oneup_flysystem' => [
                'adapters' => [
                    'default_adapter' => [
                        'local' => [
                            'directory' => '.',
                        ],
                    ],
                ],
                'filesystems' => [
                    'acme' => [
                        'alias' => Filesystem::class,
                        'adapter' => 'default_adapter',
                    ],
                ],
            ],
        ]);

        $aliasName = 'League\Flysystem\FilesystemInterface $acmeFilesystem';

        self::assertTrue($container->hasAlias($aliasName));
        self::assertSame('oneup_flysystem.acme_filesystem', (string) $container->getAlias($aliasName));
    }

    /**
     * @return ContainerBuilder
     */
    private function loadExtension(array $config)
    {
        $extension = new OneupFlysystemExtension();
        $extension->load($config, $container = new ContainerBuilder());

        return $container;
    }
}