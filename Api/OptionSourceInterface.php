<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Api;

/**
 * Options of a select setting in the editor, resolved by Model\Registry when it builds the editor config.
 */
interface OptionSourceInterface
{
    /**
     * Options as [['value' => ..., 'label' => ...], ...]
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array;
}
