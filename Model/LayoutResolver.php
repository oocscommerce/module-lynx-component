<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model;

use Magento\Framework\Profiler;
use Magento\Framework\Serialize\SerializerInterface;
use OOCS\LynxComponent\Api\ComponentProviderInterface;
use OOCS\LynxComponent\Api\SampleProviderInterface;
use OOCS\LynxComponent\Helper\PreviewSample;
use OOCS\LynxComponent\Helper\Schema\PropsFilter;
use Psr\Log\LoggerInterface;

/**
 * Resolves a stored layout for rendering: numbers the nodes, fills declared defaults, runs or defers each provider,
 * and keeps only the props each component's .graphqls declares.
 */
class LayoutResolver
{
    /**
     * What a zone's node ids start with; a zone not listed is prefixed with its name
     */
    private const ID_PREFIX = ['header' => 'h', 'content' => 'c', 'footer' => 'f', 'backstage' => 'bs'];

    /**
     * Where the nodes being resolved come from, for the log: page path and zone
     *
     * @var string
     */
    private string $source = '';

    /**
     * Component registry, a serializer for the per-request props cache key, preview sample flag, props filter, logger
     *
     * @param Registry $registry
     * @param SerializerInterface $serializer
     * @param PreviewSample $previewSample
     * @param PropsFilter $propsFilter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly SerializerInterface $serializer,
        private readonly PreviewSample $previewSample,
        private readonly PropsFilter $propsFilter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve every zone of a layout
     *
     * @param array $layout
     * @param bool $clean drop hidden and unregistered nodes and editor-only keys, and number the nodes; without it an
     *     unregistered node is marked `unavailable`
     * @param callable|null $deferRegistrar fn(string $key, callable $cb, string $group, bool $optional): void,
     *     registers a top-level prop DynamicNode merges from; null resolves deferred providers immediately
     * @param string $path the page's layout path, for the log
     * @return array
     */
    public function resolve(
        array $layout,
        bool $clean = false,
        ?callable $deferRegistrar = null,
        string $path = ''
    ): array {
        $cache = [];

        // Profiler::start() returns immediately while the profiler is off.
        Profiler::start('lynx_layout_resolve');

        foreach ($layout as $zone => $nodes) {
            if (\is_array($nodes)) {
                // Ids restart per zone: header and footer persist across Inertia visits.
                $next = 1;
                $prefix = self::ID_PREFIX[$zone] ?? (string) $zone;
                $this->source = $path . ' (' . $zone . ')';
                $layout[$zone] = $this->resolveNodes(
                    $nodes,
                    $cache,
                    $clean,
                    $prefix,
                    $next,
                    $deferRegistrar
                );
            }
        }

        Profiler::stop('lynx_layout_resolve');

        return $layout;
    }

    /**
     * Resolve one list of nodes, slots included
     *
     * @param array $nodes
     * @param array $cache
     * @param bool $clean
     * @param string $prefix
     * @param int $next
     * @param callable|null $deferRegistrar
     * @return array
     */
    private function resolveNodes(
        array $nodes,
        array &$cache,
        bool $clean,
        string $prefix,
        int &$next,
        ?callable $deferRegistrar = null
    ): array {
        $dropped = false;
        foreach ($nodes as $key => &$node) {
            if (!\is_array($node)) {
                continue;
            }

            if ($this->isDropped($node, $clean)) {
                unset($nodes[$key]);
                $dropped = true;
                continue;
            }

            $name = $node['name'] ?? '';

            if ($clean) {
                // The key DynamicNode renders the node by; whatever id came with the node is replaced.
                $node['id'] = $prefix . ($next++);
                unset($node['label'], $node['visible'], $node['custom_name']);
            }

            $props = $this->nodeProps($name, $node['props'] ?? null, $cache, $deferRegistrar);
            if ($props !== null) {
                $node['props'] = $props;
            }

            $node = $this->resolveSlots($node, $cache, $clean, $prefix, $next, $deferRegistrar);
        }
        unset($node);

        // A dropped node leaves a gap, and a list with gaps is serialized as an object
        return $dropped ? array_values($nodes) : $nodes;
    }

    /**
     * A node's props: declared defaults filled in, then resolved by its provider or filtered to what it declares
     *
     * Null when the node has no props and gets none, so none are added.
     *
     * @param string $name
     * @param array|null $props
     * @param array $cache
     * @param callable|null $deferRegistrar
     * @return array|null
     */
    private function nodeProps(string $name, ?array $props, array &$cache, ?callable $deferRegistrar): ?array
    {
        // Declared defaults under the node's props: components and providers always read a full set.
        $defaults = $this->registry->getDefaults($name);
        if ($defaults) {
            $props = ($props ?? []) + $defaults;
        }

        $provider = $name === '' ? null : $this->registry->getProvider($name);
        if ($provider !== null) {
            return $this->resolveProps($name, $provider, $props ?? [], $cache, $deferRegistrar);
        }

        return $name !== '' && $props !== null ? $this->propsFilter->filter($name, $props) : $props;
    }

    /**
     * Whether the storefront leaves a node out: hidden in the editor, or its module disabled or removed (logged).
     *
     * The editor's preview keeps an unregistered node and marks it `unavailable`, for a placeholder.
     *
     * @param array $node
     * @param bool $clean
     * @return bool
     */
    private function isDropped(array &$node, bool $clean): bool
    {
        // A module-injected node says nothing about visibility, which means it renders.
        if ($clean && !($node['visible'] ?? true)) {
            return true;
        }

        // A node without a name is put there by code (the preview bridge) and rendered by its component alone
        $name = (string) ($node['name'] ?? '');
        if ($name === '' || $this->registry->has($name)) {
            return false;
        }
        if (!$clean) {
            $node['unavailable'] = true;
            return false;
        }

        $this->logger->error(\sprintf(
            'Lynx layout %s holds node "%s" of component "%s", which is not registered; it is skipped.',
            $this->source,
            $name,
            (string) ($node['component'] ?? '')
        ));

        return true;
    }

    /**
     * Resolve the children in every slot of a node
     *
     * @param array $node
     * @param array $cache
     * @param bool $clean
     * @param string $prefix
     * @param int $next
     * @param callable|null $deferRegistrar
     * @return array
     */
    private function resolveSlots(
        array $node,
        array &$cache,
        bool $clean,
        string $prefix,
        int &$next,
        ?callable $deferRegistrar
    ): array {
        $slots = \is_array($node['slots'] ?? null) ? $node['slots'] : [];
        foreach ($slots as $slot => $children) {
            if (\is_array($children)) {
                $node['slots'][$slot] = $this->resolveNodes(
                    $children,
                    $cache,
                    $clean,
                    $prefix,
                    $next,
                    $deferRegistrar
                );
            }
        }

        return $node;
    }

    /**
     * The props a provider answers for a node, or the stored props plus `_key` when it is deferred or optional
     *
     * @param string $name
     * @param ComponentProviderInterface $provider
     * @param array $props
     * @param array $cache
     * @param callable|null $deferRegistrar
     * @return array
     */
    private function resolveProps(
        string $name,
        ComponentProviderInterface $provider,
        array $props,
        array &$cache,
        ?callable $deferRegistrar
    ): array {
        $cacheKey = $name . ':' . hash('sha256', $this->serializer->serialize($props));

        if (!isset($cache[$cacheKey])) {
            // Opened before the first call on the \Proxy provider, so building it counts toward the component.
            Profiler::start('lynx_component:' . $name);

            // Optional wins over deferred: a prop never announced cannot be auto-fetched.
            $optional = $provider->optional($props);
            // Sample preview: resolve() first, called from here so plugins on it run, then the sample over it
            $resolve = fn () => $this->propsFilter->filter(
                $name,
                $provider instanceof SampleProviderInterface && $this->previewSample->isActive()
                    ? $provider->resolveSample($provider->resolve($props), $props)
                    : $provider->resolve($props)
            );

            if (($optional || $provider->deferred($props)) && $deferRegistrar !== null) {
                // Keyed by component name (what DynamicNode looks up); two nodes of one component share it.
                ($deferRegistrar)(
                    $name,
                    $resolve,
                    $provider->deferredGroup($props),
                    $optional
                );
                // `_key` lets a component ask for its own data (router.reload({ only: [_key] })).
                $cache[$cacheKey] = $props + ['_key' => $name];
            } else {
                $cache[$cacheKey] = $resolve();
            }

            Profiler::stop('lynx_component:' . $name);
        }

        return $cache[$cacheKey];
    }
}
