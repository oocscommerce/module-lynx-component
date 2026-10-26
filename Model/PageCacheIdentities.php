<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model;

/**
 * Request-scoped FPC cache identities from component providers, exposed by Block\Inertia to the tag collector.
 */
class PageCacheIdentities
{
    /**
     * @var string[]
     */
    private array $identities = [];

    /**
     * Add identities to the page
     *
     * @param string[] $identities
     * @return void
     */
    public function add(array $identities): void
    {
        $this->identities = array_merge($this->identities, $identities);
    }

    /**
     * Every identity added so far, once each
     *
     * @return string[]
     */
    public function get(): array
    {
        return array_values(array_unique($this->identities));
    }
}
