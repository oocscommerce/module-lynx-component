<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Helper\Schema;

use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Helper\Schema\TypeScript;
use PHPUnit\Framework\TestCase;

/**
 * props.d.ts: enums as unions, types as interfaces, one props interface per component with siblings optional
 */
class TypeScriptTest extends TestCase
{
    /**
     * The generated file for two codes sharing a template
     *
     * @return string
     */
    private function generate(): string
    {
        $field = fn (string $type, array $more = []): array => $more + [
            'type' => $type, 'list' => 0, 'nullable' => false, 'itemNullable' => false, 'optional' => false,
            'description' => null,
        ];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn([
            'enums' => ['YesNo' => ['description' => null, 'values' => ['yes' => 'Yes', 'no' => 'No']]],
            'inputs' => ['Slide' => ['description' => null, 'fields' => ['title' => $field('LocalizedText')]]],
            'types' => ['Product' => ['description' => 'A card', 'fields' => [
                'id'    => $field('Int'),
                'price' => $field('Float', ['nullable' => true]),
            ]]],
            'props' => [
                'default_listing' => ['type' => 'Listing', 'template' => 'A::Listing', 'fields' => [
                    'toolbar'  => $field('YesNo'),
                    'products' => $field('Product', ['list' => 1, 'optional' => true]),
                    'labels'   => $field('StringMap', ['description' => 'Every string']),
                ]],
                'default_search' => ['type' => 'Search', 'template' => 'A::Listing', 'fields' => [
                    'products' => $field('Product', ['list' => 1, 'optional' => true]),
                    'grid'     => $field('Json', ['list' => 2, 'itemNullable' => true]),
                ]],
            ],
            'extensions' => [
                'containers' => [
                    'listing.item.after' => ['host' => 'A::Listing', 'context' => 'Product', 'blocks' => []],
                    'listing.after' => ['host' => 'A::Listing', 'context' => null, 'blocks' => []],
                ],
                'events' => ['listing_add_before' => ['data' => 'Product', 'observers' => []]],
            ],
        ]);

        return (new TypeScript($config))->generate();
    }

    /**
     * Enum, interface, nullable and list members
     *
     * @return void
     */
    public function testTypes(): void
    {
        $out = $this->generate();

        $this->assertStringContainsString("declare namespace Lynx {\n", $out);
        $this->assertStringContainsString("  type YesNo = 'yes' | 'no'\n", $out);
        $this->assertStringContainsString("  interface Slide {\n    title: string\n  }\n", $out);
        $this->assertStringContainsString(
            "  /** A card */\n  interface Product {\n    id: number\n    price: number | null\n",
            $out
        );
        $this->assertStringContainsString("  /** default_listing */\n  interface Listing {\n", $out);
        $this->assertStringContainsString("    products?: Product[]\n", $out);
        $this->assertStringContainsString("    /** Every string */\n    labels: Record<string, string>\n", $out);
        $this->assertStringContainsString("    grid: (any | null)[][]\n", $out);
    }

    /**
     * A component gets the fields only its siblings declare as optional, and _key
     *
     * @return void
     */
    public function testSiblingFields(): void
    {
        $out = $this->generate();
        $search = substr($out, strpos($out, 'interface Search {'));
        $listing = substr($out, 0, strpos($out, 'interface Search {'));

        $this->assertStringContainsString("    toolbar?: YesNo\n", $search);
        $this->assertStringContainsString("    grid?: (any | null)[][]\n", $listing);
        $this->assertStringContainsString("    _key?: string\n", $search);
    }

    /**
     * Container contexts and event data, keyed by their names
     *
     * @return void
     */
    public function testContainersAndEvents(): void
    {
        $out = $this->generate();

        $this->assertStringContainsString(
            "  interface Containers {\n    'listing.item.after': Product\n    'listing.after': undefined\n  }\n",
            $out
        );
        $this->assertStringContainsString("  interface Events {\n    'listing_add_before': Product\n  }\n}\n", $out);
    }
}
