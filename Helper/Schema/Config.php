<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper\Schema;

use Magento\Framework\Serialize\SerializerInterface;
use OOCS\LynxComponent\Model\Cache\Type\ComponentCache;

/**
 * The merged etc/components/*.graphqls, kept in the lynx_component cache
 */
class Config
{
    private const CACHE_ID = 'lynx_component_schema';

    /**
     * Loaded once per request
     *
     * @var array|null
     */
    private ?array $data = null;

    /**
     * Reader, cache, serializer
     *
     * @param Reader $reader
     * @param ComponentCache $cache
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly Reader $reader,
        private readonly ComponentCache $cache,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Components, props per code, data types, setting item types and enums
     *
     * @return array{components: array, props: array, types: array, inputs: array, enums: array}
     */
    public function get(): array
    {
        if ($this->data === null) {
            $cached = $this->cache->load(self::CACHE_ID);
            $data = $cached ? $this->serializer->unserialize($cached) : null;

            if (!\is_array($data)) {
                $data = $this->reader->read();
                $this->cache->save($this->serializer->serialize($data), self::CACHE_ID);
            }

            $this->data = $data;
        }

        return $this->data;
    }
}
