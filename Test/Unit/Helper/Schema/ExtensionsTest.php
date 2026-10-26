<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Helper\Schema;

use Magento\Framework\Serialize\Serializer\Json;
use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Helper\Schema\Extensions;
use PHPUnit\Framework\TestCase;

/**
 * extensions.json: blocks per container and observers per event, paths in the registry's form
 */
class ExtensionsTest extends TestCase
{
    /**
     * Module::path becomes Module/path; an empty schema still gives two objects
     *
     * @return void
     */
    public function testGenerate(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnOnConsecutiveCalls(
            ['extensions' => [
                'containers' => ['listing.item.after' => [
                    'host' => 'A_One::Components/Listing',
                    'context' => 'Product',
                    'blocks' => [['name' => 'gift', 'template' => 'B_Two::Components/Gift']],
                ]],
                'events' => ['listing_add_before' => [
                    'host' => 'A_One::Components/Listing',
                    'data' => null,
                    'observers' => [['name' => 'gift', 'instance' => 'B_Two::observers/gift']],
                ]],
            ]],
            []
        );
        $extensions = new Extensions($config, new Json());

        $this->assertSame(
            '{"containers":{"listing.item.after":{"host":"A_One\/Components\/Listing","blocks":'
            . '[{"name":"gift","template":"B_Two\/Components\/Gift"}]}},'
            . '"events":{"listing_add_before":{"host":"A_One\/Components\/Listing",'
            . '"observers":[{"name":"gift","instance":"B_Two\/observers\/gift"}]}}}',
            $extensions->generate()
        );
        $this->assertSame('{"containers":{},"events":{}}', $extensions->generate());
    }
}
