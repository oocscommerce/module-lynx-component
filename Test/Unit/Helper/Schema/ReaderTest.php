<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Helper\Schema;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\ObjectManager\TMapFactory;
use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Helper\Schema\PropsFilter;
use OOCS\LynxComponent\Helper\Schema\Reader;
use OOCS\LynxComponent\Model\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * etc/components/*.graphqls merged across modules: pool entries, inheritance, extensions
 */
class ReaderTest extends TestCase
{
    private const BASE = <<<'SDL'
        enum YesNo {
          YES @label(text: "Yes")
          NO @label(text: "No")
        }

        enum Ratio {
          V16_9 @label(text: "16:9") @value(text: "16_9")
        }

        "A product grid"
        type Listing @component(
          code: "default_listing"
          template: "A_One::Components/Listing"
          provider: "A\\One\\Provider\\Listing"
          label: "Listing"
          group: "product_list"
          zones: ["content"]
          availableOn: ["catalog_category_view"]
        ) {
          "Columns on desktop"
          columns: Int! @setting(label: "Columns", default: 3, min: 2, max: 6)
          toolbar: YesNo! @setting(label: "Toolbar", default: "yes")
          ratio: Ratio! @setting(label: "Ratio", default: "16_9", dependsOn: {toolbar: "yes"})
          links: [ListingLink!]! @setting(label: "Links", default: [], labelField: "label")
          after_title: Slot @slot(label: "Below Title", allow: ["text"])
          info: Slot @slot(label: "Info", name: "product.info", max: 1)
          products: [Product!]! @optional
          galleries: [[String!]!]
        }

        type Search @component(code: "default_search", template: "A_One::Components/Listing", label: "Search")
          @inherit(from: "Listing", except: ["toolbar", "after_title"])

        input ListingLink {
          label: LocalizedText! @setting(label: "Label")
        }

        type Product {
          id: Int!
          name: String
        }
        SDL;

    /**
     * What a second module does to settings: add one, replace one with another type, remove one, grow an enum
     */
    private const EXTEND_SETTINGS = <<<'SDL'
        enum Size {
          SM @label(text: "Small")
          MD @label(text: "Medium")
        }

        extend enum YesNo {
          MAYBE @label(text: "Maybe")
        }

        extend type Listing {
          columns: Size! @setting(label: "Columns", default: "md", dependsOn: {toolbar: ["yes", "maybe"]}) @replace
          links: [ListingLink!]! @remove
          per_row: Int! @setting(label: "Per Row", default: 2, min: 1, max: 4)
        }
        SDL;

    /**
     * What a second module does to provider data and slots
     */
    private const EXTEND_DATA = <<<'SDL'
        extend type Listing {
          products: [Product!] @replace
          galleries: [[String!]!] @remove
          info: Slot @remove
          badge: String @optional
        }
        SDL;

    /**
     * Files by module, written under a temp dir per test
     *
     * @var string
     */
    private string $dir;

    /**
     * Temp dir
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lynx-sdl-' . uniqid();
    }

    /**
     * Remove the temp dir
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/components/*.graphqls') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*/components') ?: [] as $dir) {
            rmdir($dir);
            rmdir(\dirname($dir));
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * A reader over modules A_One and B_Two in that order, each with the .graphqls given
     *
     * @param array<string, string> $files module => content
     * @return Reader
     */
    private function reader(array $files): Reader
    {
        foreach ($files as $module => $content) {
            mkdir($this->dir . '/' . $module . '/components', 0777, true);
            file_put_contents($this->dir . '/' . $module . '/components/Test.graphqls', $content);
        }

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(['A_One', 'B_Two']);
        $dirReader = $this->createMock(ModuleDirReader::class);
        $dirReader->method('getModuleDir')
            ->willReturnCallback(fn (...$args) => $this->dir . '/' . $args[1]);

        return new Reader($moduleList, $dirReader, new File());
    }

    /**
     * A component comes out in the pool shape: settings by control, options in order, slots under their stored name
     *
     * @return void
     */
    public function testPoolEntry(): void
    {
        $entry = $this->reader(['A_One' => self::BASE])->read()['components']['default_listing'];

        $this->assertSame('A_One::Components/Listing', $entry['component']);
        $this->assertSame('A\One\Provider\Listing', $entry['provider']);
        $this->assertSame('A product grid', $entry['description']);
        $this->assertSame(['content' => 'content'], $entry['zones']);
        $this->assertSame(['catalog_category_view'], $entry['available_on']);
        $this->assertSame(['columns', 'toolbar', 'ratio', 'links'], array_keys($entry['settings']));
        $this->assertSame(
            ['label' => 'Columns', 'description' => 'Columns on desktop', 'type' => 'number', 'default' => 3,
                'min' => 2, 'max' => 6],
            $entry['settings']['columns']
        );
        $this->assertSame(['yes', 'no'], array_keys($entry['settings']['toolbar']['options']));
        $this->assertSame(['value' => '16_9', 'label' => '16:9'], $entry['settings']['ratio']['options']['16_9']);
        $this->assertSame(['toolbar' => 'yes'], $entry['settings']['ratio']['depends']);
        $this->assertSame('array', $entry['settings']['links']['type']);
        $this->assertSame('text_i18n', $entry['settings']['links']['fields']['label']['type']);
        $this->assertSame(['after_title', 'product.info'], array_keys($entry['slots']));
        $this->assertSame(1, $entry['slots']['product.info']['max_per_component']);
    }

    /**
     * @inherit copies the parent's fields but those excepted; props leave slots out and keep list depth
     *
     * @return void
     */
    public function testInheritAndProps(): void
    {
        $data = $this->reader(['A_One' => self::BASE])->read();

        $this->assertSame(['columns', 'ratio', 'links'], array_keys($data['components']['default_search']['settings']));
        $this->assertSame(['product.info'], array_keys($data['components']['default_search']['slots']));
        $this->assertSame(
            ['columns', 'toolbar', 'ratio', 'links', 'products', 'galleries'],
            array_keys($data['props']['default_listing']['fields'])
        );
        $this->assertTrue($data['props']['default_listing']['fields']['products']['optional']);
        $this->assertSame(2, $data['props']['default_listing']['fields']['galleries']['list']);
        $this->assertTrue($data['types']['Product']['fields']['name']['nullable']);
        $this->assertSame('LocalizedText', $data['inputs']['ListingLink']['fields']['label']['type']);
    }

    /**
     * A later module adds, replaces and removes fields, and overrides @component arguments
     *
     * @return void
     */
    public function testExtend(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => <<<'SDL'
            extend type Listing @component(label: "Products") {
              columns: Int! @setting(label: "Columns", default: 4) @replace
              toolbar: YesNo @remove
              badge: String @optional
            }
            extend type Product {
              sku: String!
            }
            SDL])->read();

        $listing = $data['components']['default_listing'];
        $this->assertSame('Products', $listing['label']);
        $this->assertSame(4, $listing['settings']['columns']['default']);
        $this->assertArrayNotHasKey('toolbar', $listing['settings']);
        $this->assertArrayHasKey('badge', $data['props']['default_listing']['fields']);
        $this->assertArrayHasKey('sku', $data['types']['Product']['fields']);
    }

    /**
     * Declaring an existing field again without @replace is an error naming the file
     *
     * @return void
     */
    public function testExtendClash(): void
    {
        $reader = $this->reader(['A_One' => self::BASE, 'B_Two' => 'extend type Product { id: Int! }']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Product\.id exists: add @replace.*B_Two/');
        $reader->read();
    }

    /**
     * A type declared twice is an error
     *
     * @return void
     */
    public function testDuplicateType(): void
    {
        $reader = $this->reader(['A_One' => self::BASE, 'B_Two' => 'type Product { id: Int! }']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Product is declared in .*A_One/');
        $reader->read();
    }

    /**
     * extend type Listing @remove takes the component out
     *
     * @return void
     */
    public function testRemoveComponent(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => 'extend type Search @remove'])->read();

        $this->assertArrayNotHasKey('default_search', $data['components']);
        $this->assertArrayHasKey('default_listing', $data['components']);
    }

    /**
     * A config handing out what the reader merged, as the cache would
     *
     * @param array $data
     * @return Config
     */
    private function config(array $data): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn($data);

        return $config;
    }

    /**
     * A setting added by another module lands after the others, in the editor, the defaults and the props
     *
     * @return void
     */
    public function testExtendAddsSetting(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_SETTINGS])->read();
        $registry = new Registry($this->config($data), $this->createMock(TMapFactory::class));

        $listing = $data['components']['default_listing'];
        $this->assertSame(['columns', 'toolbar', 'ratio', 'per_row'], array_keys($listing['settings']));
        $this->assertSame(
            ['label' => 'Per Row', 'type' => 'number', 'default' => 2, 'min' => 1, 'max' => 4],
            $listing['settings']['per_row']
        );
        $this->assertSame(2, $registry->getDefaults('default_listing')['per_row']);
        $this->assertArrayHasKey('per_row', $data['props']['default_listing']['fields']);
    }

    /**
     * A replaced setting keeps its place and takes the new type's control, options, default and dependsOn
     *
     * @return void
     */
    public function testExtendReplacesSettingType(): void
    {
        $columns = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_SETTINGS])->read()
            ['components']['default_listing']['settings']['columns'];

        $this->assertSame('select', $columns['type']);
        $this->assertSame(['sm', 'md'], array_keys($columns['options']));
        $this->assertSame('md', $columns['default']);
        $this->assertSame(['toolbar' => ['yes' => 'yes', 'maybe' => 'maybe']], $columns['depends']);
        $this->assertArrayNotHasKey('min', $columns);
    }

    /**
     * A removed setting leaves the editor, the defaults and the props
     *
     * @return void
     */
    public function testExtendRemovesSetting(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_SETTINGS])->read();
        $registry = new Registry($this->config($data), $this->createMock(TMapFactory::class));

        $this->assertArrayNotHasKey('links', $data['components']['default_listing']['settings']);
        $this->assertArrayNotHasKey('links', $registry->getDefaults('default_listing'));
        $this->assertArrayNotHasKey('links', $data['props']['default_listing']['fields']);
    }

    /**
     * extend enum adds an option at the end of every select using the enum, stored lowercased
     *
     * @return void
     */
    public function testExtendEnum(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_SETTINGS])->read();

        $this->assertSame(
            ['yes', 'no', 'maybe'],
            array_keys($data['components']['default_listing']['settings']['toolbar']['options'])
        );
        $this->assertSame('Maybe', $data['enums']['YesNo']['values']['maybe']);
    }

    /**
     * A component inheriting another gets what a module added to the parent, but what it excepts
     *
     * @return void
     */
    public function testExtendReachesInheritingComponent(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_SETTINGS . self::EXTEND_DATA])->read();
        $search = $data['components']['default_search'];

        $this->assertSame(['columns', 'ratio', 'per_row'], array_keys($search['settings']));
        $this->assertSame('select', $search['settings']['columns']['type']);
        $this->assertArrayNotHasKey('toolbar', $search['settings']);
        $this->assertArrayHasKey('badge', $data['props']['default_search']['fields']);
        $this->assertArrayNotHasKey('galleries', $data['props']['default_search']['fields']);
    }

    /**
     * Provider data replaced, removed and added; a slot removed
     *
     * @return void
     */
    public function testExtendData(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_DATA])->read();
        $fields = $data['props']['default_listing']['fields'];

        $this->assertTrue($fields['products']['nullable']);
        $this->assertFalse($fields['products']['optional']);
        $this->assertArrayNotHasKey('galleries', $fields);
        $this->assertTrue($fields['badge']['optional']);
        $this->assertSame(['after_title'], array_keys($data['components']['default_listing']['slots']));
    }

    /**
     * Once a module removes a field, the props filter drops it though the provider still sends it
     *
     * @return void
     */
    public function testRemovedDataIsFiltered(): void
    {
        $data = $this->reader(['A_One' => self::BASE, 'B_Two' => self::EXTEND_DATA])->read();
        $filter = new PropsFilter(
            $this->config($data),
            $this->createMock(State::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame(
            ['products' => [['id' => 1]], 'badge' => 'New'],
            $filter->filter('default_listing', [
                'products'  => [['id' => 1, 'cost' => 9]],
                'galleries' => [['a.jpg']],
                'badge'     => 'New',
            ])
        );
    }

    /**
     * @replace on a setting neither type declares is an error naming the file
     *
     * @return void
     */
    public function testReplaceMissingSetting(): void
    {
        $reader = $this->reader([
            'A_One' => self::BASE,
            'B_Two' => 'extend type Listing { width: Int! @setting(label: "Width") @replace }',
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Listing\.width is not there to replace or remove.*B_Two/');
        $reader->read();
    }

    /**
     * @remove on a data field the type does not declare is an error naming the file
     *
     * @return void
     */
    public function testRemoveMissingField(): void
    {
        $reader = $this->reader(['A_One' => self::BASE, 'B_Two' => 'extend type Product { sku: String @remove }']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Product\.sku is not there to replace or remove.*B_Two/');
        $reader->read();
    }

    /**
     * The host's container and event, as A_One adds them to its own type
     */
    private const HOST = <<<'SDL'
        extend type Listing
          @container(name: "listing.item.after", context: "Product")
          @event(name: "listing_add_before", data: "Product")
        SDL;

    /**
     * Blocks keep declaration order but for before/after; observers keep it; host and context come along
     *
     * @return void
     */
    public function testContainerBlocksAndObservers(): void
    {
        $extensions = $this->reader([
            'A_One' => self::BASE . self::HOST,
            'B_Two' => <<<'SDL'
                extend type Listing
                  @block(container: "listing.item.after", name: "gift", template: "B_Two::Components/Gift")
                  @block(
                    container: "listing.item.after", name: "note", template: "B_Two::Components/Note", before: "gift"
                  )
                  @block(
                    container: "listing.item.after", name: "badge", template: "B_Two::Components/Badge", before: "-"
                  )
                  @observer(event: "listing_add_before", name: "gift", instance: "B_Two::observers/gift")
                SDL,
        ])->read()['extensions'];

        $container = $extensions['containers']['listing.item.after'];
        $this->assertSame('A_One::Components/Listing', $container['host']);
        $this->assertSame('Product', $container['context']);
        $this->assertSame(['badge', 'note', 'gift'], array_column($container['blocks'], 'name'));
        $this->assertSame('B_Two::Components/Gift', $container['blocks'][2]['template']);
        $this->assertSame('Product', $extensions['events']['listing_add_before']['data']);
        $this->assertSame('A_One::Components/Listing', $extensions['events']['listing_add_before']['host']);
        $this->assertSame(
            [['name' => 'gift', 'instance' => 'B_Two::observers/gift']],
            $extensions['events']['listing_add_before']['observers']
        );
    }

    /**
     * A later block of the same name takes the earlier one's place; remove drops a block or an observer
     *
     * @return void
     */
    public function testBlockReplacedAndRemoved(): void
    {
        $extensions = $this->reader([
            'A_One' => self::BASE . self::HOST . <<<'SDL'

                extend type Listing
                  @block(container: "listing.item.after", name: "gift", template: "A_One::Components/Gift")
                  @block(container: "listing.item.after", name: "note", template: "A_One::Components/Note")
                  @observer(event: "listing_add_before", name: "gift", instance: "A_One::observers/gift")
                SDL,
            'B_Two' => <<<'SDL'
                extend type Listing
                  @block(container: "listing.item.after", name: "gift", template: "B_Two::Components/Gift")
                  @block(container: "listing.item.after", name: "note", remove: true)
                  @observer(event: "listing_add_before", name: "gift", remove: true)
                SDL,
        ])->read()['extensions'];

        $this->assertSame(
            [['name' => 'gift', 'template' => 'B_Two::Components/Gift']],
            $extensions['containers']['listing.item.after']['blocks']
        );
        $this->assertSame([], $extensions['events']['listing_add_before']['observers']);
    }

    /**
     * A block for a container nobody declares is an error naming the file
     *
     * @return void
     */
    public function testBlockForMissingContainer(): void
    {
        $reader = $this->reader([
            'A_One' => self::BASE,
            'B_Two' => 'extend type Listing @block(container: "nowhere", name: "gift", template: "B_Two::X")',
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/@block gift: there is no container nowhere.*B_Two/');
        $reader->read();
    }
}
