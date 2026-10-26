<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper\Schema;

use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

/**
 * Keeps only the props a component's .graphqls declares, nested types included; developer mode logs what it drops
 */
class PropsFilter
{
    /**
     * Keys every node keeps: `_key` lets a deferred component ask for its own data
     */
    private const RESERVED = ['_key' => true];

    /**
     * Dropped paths already logged this request
     *
     * @var array<string, true>
     */
    private array $logged = [];

    /**
     * Schema, app state, logger
     *
     * @param Config $config
     * @param State $state
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The props of a node of this component, undeclared keys dropped
     *
     * @param string $code
     * @param array $props
     * @return array
     */
    public function filter(string $code, array $props): array
    {
        $fields = $this->config->get()['props'][$code]['fields'] ?? null;

        return $fields === null ? $props : $this->object($fields, $props, $code, true);
    }

    /**
     * One object: declared keys kept, each value filtered by its type
     *
     * @param array $fields
     * @param array $value
     * @param string $path
     * @param bool $top
     * @return array
     */
    private function object(array $fields, array $value, string $path, bool $top = false): array
    {
        foreach ($value as $key => $item) {
            if (isset($fields[$key])) {
                $value[$key] = $this->value($fields[$key], $item, $path . '.' . $key);
            } elseif (!$top || !isset(self::RESERVED[$key])) {
                unset($value[$key]);
                $this->logDropped($path . '.' . $key);
            }
        }

        return $value;
    }

    /**
     * A field value: lists item by item at every depth, declared object types recursed into, anything else as is
     *
     * @param array $field
     * @param mixed $value
     * @param string $path
     * @return mixed
     */
    private function value(array $field, mixed $value, string $path): mixed
    {
        $type = $this->config->get()['types'][$field['type']] ?? null;
        if ($type === null || !\is_array($value)) {
            return $value;
        }

        if ($field['list']) {
            $item = ['list' => $field['list'] - 1] + $field;
            foreach ($value as $i => $entry) {
                $value[$i] = $this->value($item, $entry, $path . '[]');
            }

            return $value;
        }

        return $this->object($type['fields'], $value, $path);
    }

    /**
     * Developer mode only, once per path: a dropped key is a .graphqls that does not match its provider
     *
     * @param string $path
     * @return void
     */
    private function logDropped(string $path): void
    {
        if (isset($this->logged[$path]) || $this->state->getMode() !== State::MODE_DEVELOPER) {
            return;
        }

        $this->logged[$path] = true;
        $this->logger->warning(
            \sprintf('Lynx props: %s is not declared in etc/components/*.graphqls, dropped.', $path)
        );
    }
}
