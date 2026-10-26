<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model;

use OOCS\LynxComponent\Api\ComponentProviderInterface;

/**
 * Base of a component provider: resolved with the page unless a subclass says otherwise.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class AbstractComponentProvider implements ComponentProviderInterface
{
    /**
     * Whether the props are left out of the page response and fetched in a partial request
     *
     * @param array $props
     * @return bool
     */
    public function deferred(array $props): bool
    {
        return false;
    }

    /**
     * Group the deferred request is made under
     *
     * @param array $props
     * @return string
     */
    public function deferredGroup(array $props): string
    {
        return 'default';
    }

    /**
     * Whether the props are fetched only when the component asks for them
     *
     * @param array $props
     * @return bool
     */
    public function optional(array $props): bool
    {
        return false;
    }

    /**
     * Reduce catalog text to plain text: strip tags, decode entities, trim
     *
     * @param string $value
     * @return string
     */
    protected function toPlainText(string $value): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
    }
}
