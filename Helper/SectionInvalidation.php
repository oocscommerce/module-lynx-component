<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper;

use Magento\Customer\Block\SectionConfig;

/**
 * Which customer-data sections an action invalidates, from the merged sections.xml (Customer\Block\SectionConfig).
 * Resolved server-side because Lynx components know their endpoint up front; exact route keys only, no glob matching.
 */
class SectionInvalidation
{
    /**
     * Resolved once per request
     *
     * @var array<string, string[]>|null
     */
    private ?array $allRouteSections = null;

    /**
     * The block holding the merged route map and client-side section list
     *
     * @param SectionConfig $sectionConfig
     */
    public function __construct(
        private readonly SectionConfig $sectionConfig,
    ) {
    }

    /**
     * Section names invalidated by a request to the given route
     *
     * The "*" key means every action (messages) and is always merged; client-side sections
     * (checkout-data, cart-data) have no server source and are dropped, as core does.
     *
     * @param string $path exact action route, e.g. "checkout/cart/add"
     * @return string[]
     */
    public function getForPath(string $path): array
    {
        $allRouteSections = $this->allRouteSections ??= $this->sectionConfig->getSections();
        // Config keys are lowercase (checkout/sidebar/removeitem), routes are camelCase.
        $key = strtolower(trim($path, '/'));

        $names = array_unique(array_merge(
            $allRouteSections[$key] ?? [],
            $allRouteSections['*'] ?? []
        ));

        return array_values(array_diff($names, $this->sectionConfig->getClientSideSections()));
    }
}
