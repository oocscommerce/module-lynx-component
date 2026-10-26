<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Model;

use Magento\Framework\ObjectManager\TMapFactory;
use OOCS\LynxComponent\Api\ComponentProviderInterface;
use OOCS\LynxComponent\Api\OptionSourceInterface;
use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Model\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The component registry: what the picker offers per page, defaults, providers
 */
class RegistryTest extends TestCase
{
    /**
     * The registry over a few entries as the .graphqls reader gives them; class names resolve to $objects
     *
     * @param array $extra
     * @param array $objects
     * @return Registry
     */
    private function registry(array $extra = [], array $objects = []): Registry
    {
        $config = $this->createMock(Config::class);
        $tmapFactory = $this->createMock(TMapFactory::class);
        $tmapFactory->method('createSharedObjectsMap')
            ->willReturnCallback(fn (array $args) => array_map(fn (string $class) => $objects[$class], $args['array']));

        $config->method('get')->willReturn(['components' => $extra + [
            'heading'  => [
                'component' => 'OOCS_LynxThemeComponent::Components/Heading',
                'group'     => 'text',
                'settings'  => ['text' => ['type' => 'text', 'default' => 'Hello'], 'level' => ['type' => 'select']],
            ],
            'checkout' => [
                'component'    => 'OOCS_LynxCheckout::Components/Checkout',
                'available_on' => ['checkout' => 'checkout_index_index'],
            ],
            'product'  => [
                'component'    => 'OOCS_LynxCatalog::Components/Product/Simple',
                'available_on' => ['product' => 'catalog_product_view_type_*'],
            ],
            'injected' => ['component' => 'Vendor_Module::Components/Injected', 'hidden' => true],
        ]]);

        return new Registry($config, $tmapFactory);
    }

    /**
     * Names of the components offered on a page
     *
     * @param Registry $registry
     * @param string $path
     * @return string[]
     */
    private function offered(Registry $registry, string $path = ''): array
    {
        return array_column($registry->getComponents($path), 'name');
    }

    /**
     * Without a page every visible component is offered; a hidden one never is
     *
     * @return void
     */
    public function testHiddenNeverOffered(): void
    {
        $this->assertSame(['heading', 'checkout', 'product'], $this->offered($this->registry()));
    }

    /**
     * available_on matches the page exactly or by a trailing *, and no list means every page
     *
     * @return void
     */
    public function testAvailableOn(): void
    {
        $registry = $this->registry();

        $this->assertSame(['heading', 'checkout'], $this->offered($registry, 'checkout_index_index'));
        $this->assertSame(['heading', 'product'], $this->offered($registry, 'catalog_product_view_type_simple'));
        $this->assertSame(['heading'], $this->offered($registry, 'cms_index_index'));
    }

    /**
     * Each entry gets its name, key, module and group; the provider stays out
     *
     * @return void
     */
    public function testEntryShape(): void
    {
        $heading = $this->registry(['heading' => [
            'component' => 'OOCS_LynxThemeComponent::Components/Heading',
            'provider'  => 'Vendor\Module\Provider',
        ]])->getComponents()[0];

        $this->assertSame('heading', $heading['name']);
        $this->assertSame('OOCS_LynxThemeComponent/Components/Heading', $heading['key']);
        $this->assertSame('OOCS_LynxThemeComponent', $heading['module']);
        $this->assertSame('general', $heading['group']);
        $this->assertArrayNotHasKey('provider', $heading);
    }

    /**
     * A select's option source, nested in sub-fields too, becomes its options
     *
     * @return void
     */
    public function testOptionSources(): void
    {
        $source = $this->createMock(OptionSourceInterface::class);
        $source->method('toOptionArray')->willReturn([['value' => 'a', 'label' => 'A']]);
        $settings = $this->registry(['list' => [
            'component' => 'Vendor_Module::Components/List',
            'settings'  => [
                'mode'  => ['type' => 'select', 'source' => 'Vendor\Source'],
                'items' => [
                    'type'   => 'array',
                    'fields' => ['kind' => ['type' => 'select', 'source' => 'Vendor\Source']],
                ],
            ],
        ]], ['Vendor\Source' => $source])->getComponents()[0]['settings'];

        $this->assertSame([['value' => 'a', 'label' => 'A']], $settings['mode']['options']);
        $this->assertArrayNotHasKey('source', $settings['mode']);
        $this->assertSame([['value' => 'a', 'label' => 'A']], $settings['items']['fields']['kind']['options']);
    }

    /**
     * Names and has() include hidden components
     *
     * @return void
     */
    public function testNamesIncludeHidden(): void
    {
        $registry = $this->registry();

        $this->assertSame(['heading', 'checkout', 'product', 'injected'], $registry->getNames());
        $this->assertTrue($registry->has('injected'));
        $this->assertFalse($registry->has('nope'));
    }

    /**
     * Defaults are the settings that declare one
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $registry = $this->registry();

        $this->assertSame(['text' => 'Hello'], $registry->getDefaults('heading'));
        $this->assertSame([], $registry->getDefaults('checkout'));
    }

    /**
     * A number setting's default written as a string leaves as a number
     *
     * @return void
     */
    public function testNumberDefaults(): void
    {
        $registry = $this->registry(['video' => [
            'component' => 'OOCS_LynxThemeComponent::Components/Video',
            'settings'  => [
                'width' => ['type' => 'number', 'default' => '0'],
                'ratio' => ['type' => 'number', 'default' => '1.5'],
                'label' => ['type' => 'text', 'default' => '10'],
            ],
        ]]);

        $this->assertSame(['width' => 0, 'ratio' => 1.5, 'label' => '10'], $registry->getDefaults('video'));
    }

    /**
     * A provider is built from its class name, hidden components included; no class means no provider
     *
     * @return void
     */
    public function testProvider(): void
    {
        $provider = $this->createMock(ComponentProviderInterface::class);
        $registry = $this->registry([
            'with' => ['component' => 'A::B', 'provider' => 'Vendor\Provider', 'hidden' => true],
        ], ['Vendor\Provider' => $provider]);

        $this->assertSame($provider, $registry->getProvider('with'));
        $this->assertNull($registry->getProvider('heading'));
    }
}
