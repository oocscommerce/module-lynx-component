<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Model;

use Magento\Framework\ObjectManager\TMapFactory;
use OOCS\LynxComponent\Api\ComponentProviderInterface;
use OOCS\LynxComponent\Api\OptionSourceInterface;
use OOCS\LynxComponent\Helper\Schema\Config;

/**
 * The components declared in etc/components/*.graphqls: what the editor offers per page, providers, setting defaults.
 */
class Registry
{
    /**
     * Components with their option sources resolved, once built
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $resolved = null;

    /**
     * Providers built so far, by component name
     *
     * @var array<string, ComponentProviderInterface|null>
     */
    private array $providers = [];

    /**
     * Component name => setting key => default
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $defaults = null;

    /**
     * Component name => definition, once read
     *
     * @var array<string, array>|null
     */
    private ?array $components = null;

    /**
     * Schema, and the map factory that builds a provider or option source the first time it is asked for
     *
     * @param Config $config
     * @param TMapFactory $tmapFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly TMapFactory $tmapFactory,
    ) {
    }

    /**
     * Every registered component, or only the ones a page accepts (`available_on`, `*` suffix = prefix match)
     *
     * A `hidden` component is in neither answer: only code places it on a page.
     *
     * @param string $path the page asked about; empty filters by no page
     * @return array<int, array<string, mixed>>
     */
    public function getComponents(string $path = ''): array
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach ($this->components() as $name => $c) {
                $raw = $c['component'] ?? '';
                $module = str_contains($raw, '::') ? explode('::', $raw, 2)[0] : '';

                $settings = [];
                foreach ($c['settings'] ?? [] as $field => $def) {
                    $settings[$field] = $this->resolveOptionSources($def);
                }

                $out = $c;
                $out['name'] = $name;
                $out['key'] = str_replace('::', '/', $raw);
                $out['module'] = $module;
                $out['settings'] = $settings;
                // Picker heading and the key a slot's allowed_groups names
                $out['group'] = (string) ($c['group'] ?? '') ?: 'general';
                unset($out['provider']);

                $this->resolved[] = $out;
            }
        }

        // `hidden` keeps a component out of the picker, not out of the provider map.
        $offered = array_values(array_filter(
            $this->resolved,
            static fn (array $component): bool => empty($component['hidden'])
        ));

        if ($path === '') {
            return $offered;
        }

        return array_values(array_filter(
            $offered,
            fn (array $component): bool => $this->isAvailableOn($component, $path)
        ));
    }

    /**
     * Whether a component's `available_on` names the page; nothing declared means every page
     *
     * @param array $component
     * @param string $path
     * @return bool
     */
    private function isAvailableOn(array $component, string $path): bool
    {
        // An array out of di.xml keeps its keys; the paths are the values.
        $paths = array_values((array) ($component['available_on'] ?? []));
        if (!$paths) {
            return true;
        }

        foreach ($paths as $allowed) {
            $allowed = (string) $allowed;
            if ($allowed === $path || (str_ends_with($allowed, '*') && str_starts_with($path, rtrim($allowed, '*')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A select setting may name a `source` (OptionSourceInterface) instead of static options; sub-fields too
     *
     * @param array $def
     * @return array
     */
    private function resolveOptionSources(array $def): array
    {
        if (isset($def['source'])) {
            $def['options'] = $this->instance(OptionSourceInterface::class, $def['source'])->toOptionArray();
            unset($def['source']);
        }
        foreach ($def['fields'] ?? [] as $name => $sub) {
            if (\is_array($sub)) {
                $def['fields'][$name] = $this->resolveOptionSources($sub);
            }
        }

        return $def;
    }

    /**
     * Every registered component name, hidden ones and those of other pages included
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->components());
    }

    /**
     * Whether a component is registered under this name, hidden ones included
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->components()[$name]);
    }

    /**
     * The `default` of every setting the component declares, keyed by setting
     *
     * @param string $name
     * @return array<string, mixed>
     */
    public function getDefaults(string $name): array
    {
        if ($this->defaults === null) {
            $this->defaults = [];
            foreach ($this->components() as $componentName => $c) {
                foreach ($c['settings'] ?? [] as $field => $def) {
                    if (!\array_key_exists('default', $def)) {
                        continue;
                    }
                    $default = $def['default'];
                    // convert to number
                    if (($def['type'] ?? '') === 'number' && \is_string($default) && is_numeric($default)) {
                        $default += 0;
                    }
                    $this->defaults[$componentName][$field] = $default;
                }
            }
        }

        return $this->defaults[$name] ?? [];
    }

    /**
     * The provider of a component, built on first use; null when it declares none
     *
     * @param string $name
     * @return ComponentProviderInterface|null
     */
    public function getProvider(string $name): ?ComponentProviderInterface
    {
        if (!\array_key_exists($name, $this->providers)) {
            $class = $this->components()[$name]['provider'] ?? null;
            $this->providers[$name] = $class ? $this->instance(ComponentProviderInterface::class, $class) : null;
        }

        return $this->providers[$name];
    }

    /**
     * The shared instance of a class named in a .graphqls, checked against the type it must be
     *
     * One map per class: a map checks every class it holds when built, which would load all providers at once.
     *
     * @param string $type
     * @param string $class
     * @return object
     */
    private function instance(string $type, string $class): object
    {
        $map = $this->tmapFactory->createSharedObjectsMap(['type' => $type, 'array' => ['instance' => $class]]);

        return $map['instance'];
    }

    /**
     * Component name => definition
     *
     * @return array<string, array>
     */
    private function components(): array
    {
        return $this->components ??= $this->config->get()['components'];
    }
}
