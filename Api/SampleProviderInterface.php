<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Api;

/**
 * A provider that can feed its component sample data, for a preview of a page the editor cannot reach for real
 */
interface SampleProviderInterface
{
    /**
     * Overlay sample data on what resolve() returned (plugins on it included), for what the session cannot give here
     *
     * @param array $resolved the props resolve() returned
     * @param array $props static props saved in layout storage, for a setting resolve() dropped
     * @return array
     */
    public function resolveSample(array $resolved, array $props): array;
}
