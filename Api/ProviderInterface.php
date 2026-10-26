<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Api;

/**
 * A plain data provider: answers an array with no node props involved.
 */
interface ProviderInterface
{
    /**
     * The data
     *
     * @return array
     */
    public function getData(): array;
}
