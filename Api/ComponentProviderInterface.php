<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Api;

/**
 * A component provider: turns a node's stored props into what the component renders, now or in a later request.
 */
interface ComponentProviderInterface
{
    /**
     * Enrich stored component props with dynamic data
     *
     * @param array $props static props saved in layout storage
     * @return array merged props for the frontend component
     */
    public function resolve(array $props): array;

    /**
     * Whether resolve() is left out of the initial response and fetched after mount
     *
     * @param array $props static props saved in layout storage, so the answer can be per node
     * @return bool
     */
    public function deferred(array $props): bool;

    /**
     * Deferred props in the same group are batched into one follow-up request
     *
     * @param array $props
     * @return string
     */
    public function deferredGroup(array $props): string;

    /**
     * Whether resolve() runs only for a request that names this component (router.reload({ only: [key] }))
     *
     * Unlike deferred(), an optional prop is never announced or auto-fetched; wins when a provider says both.
     *
     * @param array $props static props saved in layout storage
     * @return bool
     */
    public function optional(array $props): bool;
}
