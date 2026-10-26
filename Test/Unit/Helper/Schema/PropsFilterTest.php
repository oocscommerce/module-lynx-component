<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Test\Unit\Helper\Schema;

use Magento\Framework\App\State;
use OOCS\LynxComponent\Helper\Schema\Config;
use OOCS\LynxComponent\Helper\Schema\PropsFilter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only declared props reach the component, nested types and lists included
 */
class PropsFilterTest extends TestCase
{
    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * A filter over one listing component, in the given app mode
     *
     * @param string $mode
     * @return PropsFilter
     */
    private function filter(string $mode = State::MODE_PRODUCTION): PropsFilter
    {
        $field = fn (string $type, int $list = 0): array => ['type' => $type, 'list' => $list, 'nullable' => true];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn([
            'props' => ['listing' => ['fields' => [
                'title'     => $field('String'),
                'products'  => $field('Product', 1),
                'galleries' => $field('Image', 2),
                'extra'     => $field('Json'),
            ]]],
            'types' => [
                'Product' => ['fields' => ['id' => $field('Int')]],
                'Image'   => ['fields' => ['src' => $field('String')]],
            ],
        ]);
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);
        $this->logger = $this->createMock(LoggerInterface::class);

        return new PropsFilter($config, $state, $this->logger);
    }

    /**
     * Undeclared keys go at every level; _key and Json values stay as they are
     *
     * @return void
     */
    public function testDropsUndeclared(): void
    {
        $out = $this->filter()->filter('listing', [
            'title'     => 'Sale',
            'secret'    => 'x',
            '_key'      => 'listing',
            'products'  => [['id' => 1, 'cost' => 9]],
            'galleries' => [[['src' => 'a.jpg', 'tmp' => '/var/a']]],
            'extra'     => ['anything' => true],
        ]);

        $this->assertSame([
            'title'     => 'Sale',
            '_key'      => 'listing',
            'products'  => [['id' => 1]],
            'galleries' => [[['src' => 'a.jpg']]],
            'extra'     => ['anything' => true],
        ], $out);
    }

    /**
     * A component without a .graphqls is left alone
     *
     * @return void
     */
    public function testUndeclaredComponent(): void
    {
        $this->assertSame(['a' => 1], $this->filter()->filter('other', ['a' => 1]));
    }

    /**
     * Developer mode logs each dropped path once
     *
     * @return void
     */
    public function testLogsInDeveloperMode(): void
    {
        $filter = $this->filter(State::MODE_DEVELOPER);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('listing.secret'));

        $filter->filter('listing', ['secret' => 1]);
        $filter->filter('listing', ['secret' => 2]);
    }
}
