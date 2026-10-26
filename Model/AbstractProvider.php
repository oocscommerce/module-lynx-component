<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model;

use OOCS\LynxComponent\Api\ProviderInterface;

/**
 * Base of a plain data provider: nothing until a subclass says otherwise.
 */
abstract class AbstractProvider implements ProviderInterface
{
    /**
     * The data
     *
     * @return array
     */
    public function getData(): array
    {
        return [];
    }
}
