<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * "Lynx Component Data" cache type: data resolved by component providers, tagged with entity cache tags too.
 */
class ComponentCache extends TagScope
{
    public const TYPE_IDENTIFIER = 'lynx_component';

    public const CACHE_TAG = 'LYNX_COMPONENT';

    /**
     * The frontend of this cache type, scoped by its tag
     *
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
