<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Model;

use Magento\Framework\ObjectManager\TMapFactory;
use Magento\Framework\Serialize\Serializer\Json;
use OOCS\LynxComponent\Api\ComponentProviderInterface;
use OOCS\LynxComponent\Helper\PreviewSample;
use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Helper\Schema\PropsFilter;
use OOCS\LynxComponent\Model\LayoutResolver;
use OOCS\LynxComponent\Model\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Layout resolution: dropped and marked nodes, ids, defaults, providers and their deferral
 */
class LayoutResolverTest extends TestCase
{
    /**
     * @var ComponentProviderInterface&MockObject
     */
    private ComponentProviderInterface $provider;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var LayoutResolver
     */
    private LayoutResolver $resolver;

    /**
     * A registry with a provided banner and a plain group
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->provider = $this->createMock(ComponentProviderInterface::class);
        $this->provider->method('deferredGroup')->willReturn('default');
        $this->logger = $this->createMock(LoggerInterface::class);
        $previewSample = $this->createMock(PreviewSample::class);

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(['components' => [
            'banner' => [
                'component' => 'Vendor_Module::Components/Banner',
                'provider'  => 'Vendor\Module\Provider',
                'settings'  => ['title' => ['type' => 'text', 'default' => 'Sale']],
            ],
            'group'  => ['component' => 'OOCS_LynxThemeComponent::Components/Group'],
        ]]);
        $tmapFactory = $this->createMock(TMapFactory::class);
        $tmapFactory->method('createSharedObjectsMap')->willReturn(['instance' => $this->provider]);

        // No props declared: the filter passes everything through
        $propsFilter = $this->createMock(PropsFilter::class);
        $propsFilter->method('filter')->willReturnArgument(1);

        $this->resolver = new LayoutResolver(
            new Registry($config, $tmapFactory),
            new Json(),
            $previewSample,
            $propsFilter,
            $this->logger
        );
    }

    /**
     * The provider answers its props plus a marker
     *
     * @return void
     */
    private function provideMarked(): void
    {
        $this->provider->method('resolve')->willReturnCallback(fn (array $props) => $props + ['resolved' => true]);
    }

    /**
     * A node is resolved by its provider over the declared defaults
     *
     * @return void
     */
    public function testProviderOverDefaults(): void
    {
        $this->provideMarked();

        $out = $this->resolver->resolve(['content' => [['name' => 'banner', 'props' => []]]]);

        $this->assertSame(['title' => 'Sale', 'resolved' => true], $out['content'][0]['props']);
    }

    /**
     * Stored props win over defaults
     *
     * @return void
     */
    public function testStoredPropsOverDefaults(): void
    {
        $this->provideMarked();

        $out = $this->resolver->resolve(['content' => [['name' => 'banner', 'props' => ['title' => 'New']]]]);

        $this->assertSame('New', $out['content'][0]['props']['title']);
    }

    /**
     * Clean output numbers nodes per zone, slots included, and drops editor-only keys
     *
     * @return void
     */
    public function testCleanIdsPerZone(): void
    {
        $this->provideMarked();
        $layout = [
            'header'  => [['name' => 'group', 'label' => 'G', 'visible' => true, 'custom_name' => 'Top']],
            'content' => [[
                'name'  => 'group',
                'id'    => 'old',
                'slots' => ['default' => [['name' => 'group']]],
            ]],
        ];

        $out = $this->resolver->resolve($layout, true);

        $this->assertSame('h1', $out['header'][0]['id']);
        $this->assertArrayNotHasKey('label', $out['header'][0]);
        $this->assertArrayNotHasKey('visible', $out['header'][0]);
        $this->assertArrayNotHasKey('custom_name', $out['header'][0]);
        $this->assertSame('c1', $out['content'][0]['id']);
        $this->assertSame('c2', $out['content'][0]['slots']['default'][0]['id']);
    }

    /**
     * A hidden node is dropped on the storefront and kept in the editor
     *
     * @return void
     */
    public function testHiddenNode(): void
    {
        $layout = ['content' => [['name' => 'group', 'visible' => false], ['name' => 'group']]];

        $this->assertCount(1, $this->resolver->resolve($layout, true)['content']);
        $this->assertCount(2, $this->resolver->resolve($layout)['content']);
    }

    /**
     * A hidden node inside a slot is dropped on the storefront
     *
     * @return void
     */
    public function testHiddenNodeInSlot(): void
    {
        $layout = ['content' => [[
            'name'  => 'group',
            'slots' => ['default' => [['name' => 'group', 'visible' => false], ['name' => 'group']]],
        ]]];

        $slot = $this->resolver->resolve($layout, true)['content'][0]['slots']['default'];

        $this->assertCount(1, $slot);
        $this->assertSame([0], array_keys($slot));
    }

    /**
     * A hidden parent takes its children with it
     *
     * @return void
     */
    public function testHiddenParentDropsChildren(): void
    {
        $layout = ['content' => [[
            'name'    => 'group',
            'visible' => false,
            'slots'   => ['default' => [['name' => 'banner']]],
        ]]];

        $this->assertSame([], $this->resolver->resolve($layout, true)['content']);
    }

    /**
     * The provider of a hidden node is never asked
     *
     * @return void
     */
    public function testHiddenNodeProviderNotCalled(): void
    {
        $this->provider->expects($this->never())->method('resolve');

        $this->resolver->resolve(['content' => [['name' => 'banner', 'visible' => false]]], true);
    }

    /**
     * A node that says nothing about visibility, or says it is visible, is shown
     *
     * @return void
     */
    public function testNodeWithoutVisibilityShown(): void
    {
        $layout = ['content' => [['name' => 'group'], ['name' => 'group', 'visible' => true]]];

        $this->assertCount(2, $this->resolver->resolve($layout, true)['content']);
    }

    /**
     * An unregistered node is dropped and logged with the layout, zone and names; the list is reindexed
     *
     * @return void
     */
    public function testUnregisteredNodeDroppedAndLogged(): void
    {
        $this->logger->expects($this->once())->method('error')->with(
            'Lynx layout cms_index_index (content) holds node "gone" of component "Vendor_Gone/Components/X", '
            . 'which is not registered; it is skipped.'
        );
        $layout = ['content' => [
            ['name' => 'gone', 'component' => 'Vendor_Gone/Components/X'],
            ['name' => 'group'],
        ]];

        $content = $this->resolver->resolve($layout, true, null, 'cms_index_index')['content'];

        $this->assertSame([0], array_keys($content));
        $this->assertSame('group', $content[0]['name']);
    }

    /**
     * An unregistered node inside a slot is dropped too, and the slot list reindexed
     *
     * @return void
     */
    public function testUnregisteredNodeInSlotDropped(): void
    {
        $layout = ['content' => [[
            'name'  => 'group',
            'slots' => ['default' => [['name' => 'gone'], ['name' => 'group'], ['name' => 'gone']]],
        ]]];

        $slot = $this->resolver->resolve($layout, true)['content'][0]['slots']['default'];

        $this->assertSame([0], array_keys($slot));
        $this->assertSame('group', $slot[0]['name']);
    }

    /**
     * The editor keeps an unregistered node and marks it unavailable
     *
     * @return void
     */
    public function testUnregisteredNodeMarkedInEditor(): void
    {
        $this->logger->expects($this->never())->method('error');

        $content = $this->resolver->resolve(['content' => [['name' => 'gone']]])['content'];

        $this->assertTrue($content[0]['unavailable']);
    }

    /**
     * A node without a name is placed by code and kept as it is
     *
     * @return void
     */
    public function testNamelessNodeKept(): void
    {
        $content = $this->resolver->resolve(['content' => [['component' => 'Bridge']]], true)['content'];

        $this->assertSame('Bridge', $content[0]['component']);
        $this->assertArrayNotHasKey('unavailable', $content[0]);
    }

    /**
     * Without drops the node keys are left as they came
     *
     * @return void
     */
    public function testKeysKeptWithoutDrops(): void
    {
        $content = $this->resolver->resolve(['content' => ['a' => ['name' => 'group']]], true)['content'];

        $this->assertSame(['a'], array_keys($content));
    }

    /**
     * Two nodes with the same props ask the provider once
     *
     * @return void
     */
    public function testProviderCalledOncePerProps(): void
    {
        $this->provider->expects($this->once())->method('resolve')->willReturn(['x' => 1]);

        $out = $this->resolver->resolve(['content' => [['name' => 'banner'], ['name' => 'banner']]]);

        $this->assertSame($out['content'][0]['props'], $out['content'][1]['props']);
    }

    /**
     * A deferred provider is registered instead of called; its callback resolves it later
     *
     * @return void
     */
    public function testDeferredProviderRegistered(): void
    {
        $this->provider->method('deferred')->willReturn(true);
        $this->provideMarked();
        $registered = [];
        $registrar = function (string $key, callable $callback, string $group, bool $optional) use (&$registered) {
            $registered[$key] = [$callback, $group, $optional];
        };

        $out = $this->resolver->resolve(['content' => [['name' => 'banner']]], true, $registrar);

        $this->assertSame(['title' => 'Sale', '_key' => 'banner'], $out['content'][0]['props']);
        [$callback, $group, $optional] = $registered['banner'];
        $this->assertSame(['default', false], [$group, $optional]);
        $this->assertSame(['title' => 'Sale', 'resolved' => true], $callback());
    }

    /**
     * Without a registrar a deferred provider is resolved at once
     *
     * @return void
     */
    public function testDeferredWithoutRegistrarResolvedAtOnce(): void
    {
        $this->provider->method('deferred')->willReturn(true);
        $this->provideMarked();

        $out = $this->resolver->resolve(['content' => [['name' => 'banner']]]);

        $this->assertTrue($out['content'][0]['props']['resolved']);
    }
}
