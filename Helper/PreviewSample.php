<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper;

/**
 * Whether this request renders a page with sample data: set by the PageBuilder preview router, read by LayoutResolver
 */
class PreviewSample
{
    /**
     * @var bool
     */
    private bool $active = false;

    /**
     * Render this request with sample data
     *
     * @return void
     */
    public function start(): void
    {
        $this->active = true;
    }

    /**
     * Is Active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }
}
