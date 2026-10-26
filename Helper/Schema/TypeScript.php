<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper\Schema;

/**
 * Writes the .graphqls as a global `Lynx` namespace: enums, data types, each component's props under its type name,
 * and the context of each container and the data of each event
 *
 * Without `!` a field may be null, with @optional it may be missing. Plain interfaces, string unions, arrays and
 * `| null` only: Vue's compiler reads this file (globalTypeFiles) to
 * declare runtime props, and it does not understand much more.
 */
class TypeScript
{
    /**
     * Scalar to TypeScript
     */
    private const SCALARS = [
        'Int' => 'number', 'Float' => 'number', 'Boolean' => 'boolean', 'String' => 'string', 'ID' => 'string',
        'Text' => 'string', 'Textarea' => 'string', 'RichText' => 'string', 'Color' => 'string', 'Image' => 'string',
        'Url' => 'string', 'LocalizedText' => 'string', 'LocalizedTextarea' => 'string',
        'LocalizedRichText' => 'string', 'StringMap' => 'Record<string, string>', 'Json' => 'any',
        'SettingValue' => 'unknown',
    ];

    /**
     * Schema
     *
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * The content of props.d.ts
     *
     * @return string
     */
    public function generate(): string
    {
        $schema = $this->config->get();

        $out = "// Generated from etc/components/*.graphqls by bin/magento lynx:static:deploy — do not edit.\n"
            . "declare namespace Lynx {\n";

        foreach ($schema['enums'] as $name => $enum) {
            $values = array_map(
                static fn ($value): string => var_export((string) $value, true),
                array_keys($enum['values'])
            );
            $out .= $this->doc($enum['description'], '  ') . "  type $name = " . implode(' | ', $values) . "\n";
        }

        foreach ($schema['types'] + $schema['inputs'] as $name => $type) {
            $out .= $this->doc($type['description'], '  ') . "  interface $name {\n"
                . $this->fields($type['fields'], []) . "  }\n";
        }

        // One interface per component, named as its type in the .graphqls
        foreach ($schema['props'] as $code => $props) {
            $out .= "  /** $code */\n  interface {$props['type']} {\n"
                . $this->fields($props['fields'], $this->siblingFields($code, $schema['props']))
                // Set by LayoutResolver on a deferred or optional node, for router.reload({ only: [_key] })
                . "    _key?: string\n"
                . "  }\n";
        }

        // Container name → context type, event name → data type
        $extensions = $schema['extensions'] ?? ['containers' => [], 'events' => []];
        $out .= "  interface Containers {\n";
        foreach ($extensions['containers'] as $name => $container) {
            $out .= '    ' . var_export((string) $name, true) . ': ' . ($container['context'] ?? 'undefined') . "\n";
        }
        $out .= "  }\n  interface Events {\n";
        foreach ($extensions['events'] as $name => $event) {
            $out .= '    ' . var_export((string) $name, true) . ': '
                . ($event['data'] ?? 'Record<string, unknown>') . "\n";
        }

        return $out . "  }\n}\n";
    }

    /**
     * Fields the other codes of the same template declare and this one does not, made optional here
     *
     * A component typed with several (`Lynx.A | Lynx.B`) is then not told a prop of one of them is required.
     *
     * @param string $code
     * @param array $all
     * @return array
     */
    private function siblingFields(string $code, array $all): array
    {
        $template = $all[$code]['template'];
        $extra = [];
        foreach ($all as $other => $props) {
            if ($other !== $code && $props['template'] === $template) {
                $extra += array_diff_key($props['fields'], $all[$code]['fields']);
            }
        }

        return $extra;
    }

    /**
     * Interface members
     *
     * @param array $fields
     * @param array $siblings
     * @return string
     */
    private function fields(array $fields, array $siblings): string
    {
        $out = '';
        foreach ($fields + $siblings as $name => $field) {
            $optional = $field['optional'] || isset($siblings[$name]);
            $type = $this->scalar($field['type']);
            if ($field['list']) {
                $type = ($field['itemNullable'] ? "($type | null)" : $type) . str_repeat('[]', $field['list']);
            }

            $out .= $this->doc($field['description'], '    ') . '    ' . $name . ($optional ? '?' : '') . ': '
                . $type . ($field['nullable'] ? ' | null' : '') . "\n";
        }

        return $out;
    }

    /**
     * A named type as TypeScript
     *
     * @param string $type
     * @return string
     */
    private function scalar(string $type): string
    {
        return self::SCALARS[$type] ?? $type;
    }

    /**
     * A one-line JSDoc comment, or nothing
     *
     * @param string|null $description
     * @param string $indent
     * @return string
     */
    private function doc(?string $description, string $indent): string
    {
        if ($description === null || $description === '') {
            return '';
        }

        return $indent . '/** ' . str_replace(["\n", '*/'], [' ', '* /'], $description) . " */\n";
    }
}
