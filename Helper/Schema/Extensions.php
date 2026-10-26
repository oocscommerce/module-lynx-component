<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper\Schema;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * Writes dist/extensions.json, which the build turns into virtual:lynx-extensions
 */
class Extensions
{
    /**
     * Schema, serializer
     *
     * @param Config $config
     * @param Json $json
     */
    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
    ) {
    }

    /**
     * The content of extensions.json, paths in the registry's `Module/path` form
     *
     * @return string
     */
    public function generate(): string
    {
        $extensions = $this->config->get()['extensions'] ?? ['containers' => [], 'events' => []];
        $path = static fn (?string $id): ?string => $id === null ? null : str_replace('::', '/', $id);

        $containers = [];
        foreach ($extensions['containers'] as $name => $container) {
            $containers[$name] = [
                // The build warns when this component does not render the container
                'host' => $path($container['host']),
                'blocks' => array_map(
                    static fn (array $block): array => [
                        'name' => $block['name'],
                        'template' => $path($block['template']),
                    ],
                    $container['blocks']
                ),
            ];
        }

        $events = [];
        foreach ($extensions['events'] as $name => $event) {
            $events[$name] = [
                'host' => $path($event['host'] ?? null),
                'observers' => array_map(
                    static fn (array $observer): array => [
                        'name' => $observer['name'],
                        'instance' => $path($observer['instance']),
                    ],
                    $event['observers']
                ),
            ];
        }

        return $this->json->serialize(['containers' => (object) $containers, 'events' => (object) $events]);
    }
}
