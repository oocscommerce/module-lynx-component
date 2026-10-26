<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper\Schema;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Merges etc/components/*.graphqls of every enabled module into the component pool, props per code, data types,
 * containers and events
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Reader
{
    /**
     * The control a scalar setting gets
     */
    private const CONTROLS = [
        'Int' => 'number', 'Float' => 'number', 'Boolean' => 'toggle', 'String' => 'text', 'Text' => 'text',
        'Textarea' => 'textarea', 'RichText' => 'richtext', 'LocalizedText' => 'text_i18n',
        'LocalizedTextarea' => 'textarea_i18n', 'LocalizedRichText' => 'richtext_i18n', 'Color' => 'color',
        'Image' => 'image', 'Url' => 'url',
    ];

    /**
     * The definitions a .graphqls is made of; scalars and directive definitions only name things
     */
    private const DEFINITIONS = [
        NodeKind::OBJECT_TYPE_DEFINITION,
        NodeKind::ENUM_TYPE_DEFINITION,
        NodeKind::INPUT_OBJECT_TYPE_DEFINITION,
    ];

    /**
     * Directives kept as lists; an extend type appends to them
     */
    private const REPEATABLE = ['container', 'block', 'event', 'observer'];

    /**
     * Module list in sequence order, module dirs, file driver
     *
     * @param ModuleListInterface $moduleList
     * @param ModuleDirReader $moduleDirReader
     * @param File $file
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ModuleDirReader $moduleDirReader,
        private readonly File $file,
    ) {
    }

    /**
     * Components (the shape Registry serves), props per code, data types, setting item types, enums, extensions
     *
     * @return array{components: array, props: array, types: array, inputs: array, enums: array, extensions: array}
     * @throws LocalizedException
     */
    public function read(): array
    {
        [$objects, $enums, $inputs] = $this->collect();

        $components = $props = $types = [];
        foreach ($objects as $name => $object) {
            $component = $object['directives']['component'] ?? null;
            if ($component === null) {
                $types[$name] = ['description' => $object['description'], 'fields' => $object['fields']];
                continue;
            }

            $fields = $this->inheritedFields($name, $objects, []);
            $code = (string) ($component['code'] ?? '');
            if ($code === '' || empty($component['template'])) {
                throw $this->error('%1 needs @component(code, template)', [$name], $object['file']);
            }
            if (isset($components[$code])) {
                throw $this->error('Component code %1 is registered twice', [$code], $object['file']);
            }

            $components[$code] = $this->component($component, $object['description'], $fields, $enums, $inputs);
            $props[$code] = [
                'type' => $name,
                'template' => $component['template'],
                'fields' => array_filter(
                    $fields,
                    static fn (array $field): bool => !isset($field['directives']['slot'])
                ),
            ];
        }

        return [
            'components' => $components,
            'props' => $this->withoutDirectives($props),
            'types' => $this->withoutDirectives($types),
            // Items of array settings: typed for the components, not filtered
            'inputs' => $this->withoutDirectives(array_map(
                static fn (array $input): array => ['description' => null] + $input,
                $inputs
            )),
            'enums' => array_map(
                static fn (array $enum): array => [
                    'description' => $enum['description'],
                    'values' => array_column($enum['values'], 'label', 'value'),
                ],
                $enums
            ),
            'extensions' => $this->extensions($objects),
        ];
    }

    /**
     * Containers with their ordered blocks, events with their observers
     *
     * @param array $objects
     * @return array{containers: array, events: array}
     * @throws LocalizedException
     */
    private function extensions(array $objects): array
    {
        $containers = $events = $blocks = $observers = [];
        foreach ($objects as $object) {
            $host = $object['directives']['component']['template'] ?? null;
            foreach ($object['directives']['container'] ?? [] as $args) {
                $entry = ['host' => $host, 'context' => $args['context'] ?? null, 'blocks' => []];
                $this->declare($containers, 'Container', $args, $entry);
            }
            foreach ($object['directives']['event'] ?? [] as $args) {
                $entry = ['host' => $host, 'data' => $args['data'] ?? null, 'observers' => []];
                $this->declare($events, 'Event', $args, $entry);
            }
            array_push($blocks, ...($object['directives']['block'] ?? []));
            array_push($observers, ...($object['directives']['observer'] ?? []));
        }

        foreach ($this->joined($containers, $blocks, 'block', 'container', 'template') as $container => $list) {
            $containers[$container]['blocks'] = $this->ordered($list);
        }
        foreach ($this->joined($events, $observers, 'observer', 'event', 'instance') as $event => $list) {
            $events[$event]['observers'] = array_values(array_map(
                static fn (array $args): array => ['name' => $args['name'], 'instance' => $args['instance']],
                $list
            ));
        }

        return ['containers' => $containers, 'events' => $events];
    }

    /**
     * Declare a container or event under its name, which must be free
     *
     * @param array $declared
     * @param string $kind
     * @param array $args
     * @param array $entry
     * @return void
     * @throws LocalizedException
     */
    private function declare(array &$declared, string $kind, array $args, array $entry): void
    {
        if (isset($declared[$args['name']])) {
            throw $this->error('%1 %2 is declared twice', [$kind, $args['name']], $args['file']);
        }
        $declared[$args['name']] = $entry;
    }

    /**
     * Blocks or observers by what they join, in declaration order: the same name replaces, remove drops
     *
     * @param array $declared containers or events
     * @param array $entries their @block or @observer arguments
     * @param string $directive
     * @param string $target the argument naming what is joined
     * @param string $required the argument an entry cannot do without
     * @return array<string,array<string,array>>
     * @throws LocalizedException
     */
    private function joined(array $declared, array $entries, string $directive, string $target, string $required): array
    {
        $joined = [];
        foreach ($entries as $args) {
            $to = $args[$target];
            if (!isset($declared[$to])) {
                $names = [$directive, $args['name'], $target, $to];
                throw $this->error('@%1 %2: there is no %3 %4', $names, $args['file']);
            }
            unset($joined[$to][$args['name']]);
            if (!empty($args['remove'])) {
                continue;
            }
            if (empty($args[$required])) {
                throw $this->error('@%1 %2 needs a %3', [$directive, $args['name'], $required], $args['file']);
            }
            $joined[$to][$args['name']] = $args;
        }

        return $joined;
    }

    /**
     * Declaration order, then each block moved by before/after; a missing sibling means last
     *
     * @param array $blocks by name
     * @return array<int, array{name: string, template: string}>
     */
    private function ordered(array $blocks): array
    {
        $names = array_keys($blocks);
        foreach ($blocks as $name => $args) {
            $sibling = $args['before'] ?? $args['after'] ?? null;
            if ($sibling === null) {
                continue;
            }
            $names = array_values(array_diff($names, [$name]));
            $at = array_search($sibling, $names, true);
            if ($sibling === '-') {
                $at = isset($args['before']) ? 0 : \count($names);
            } elseif ($at === false) {
                $at = \count($names);
            } elseif (!isset($args['before'])) {
                $at++;
            }
            array_splice($names, $at, 0, [$name]);
        }

        return array_map(
            static fn (string $name): array => ['name' => $name, 'template' => $blocks[$name]['template']],
            $names
        );
    }

    /**
     * Every definition, extensions applied in module order
     *
     * @return array{0: array, 1: array, 2: array}
     * @throws LocalizedException
     */
    private function collect(): array
    {
        $definitions = ['objects' => [], 'enums' => [], 'inputs' => []];
        $files = $extensions = [];

        foreach ($this->files() as $path) {
            foreach ($this->parse($path)->definitions as $node) {
                // Kinds rather than node classes: the AST has a class per kind
                if (\in_array($node->kind, [NodeKind::OBJECT_TYPE_EXTENSION, NodeKind::ENUM_TYPE_EXTENSION], true)) {
                    $extensions[] = [$node, $path];
                } elseif (\in_array($node->kind, self::DEFINITIONS, true)) {
                    $this->define($definitions, $files, $node, $path);
                }
            }
        }

        foreach ($extensions as [$node, $path]) {
            $this->applyExtension($definitions, $node, $path);
        }

        return [
            array_filter(
                $definitions['objects'],
                static fn (array $object): bool => !isset($object['directives']['remove'])
            ),
            $definitions['enums'],
            $definitions['inputs'],
        ];
    }

    /**
     * One .graphqls file as a document
     *
     * @param string $path
     * @return DocumentNode
     * @throws LocalizedException
     */
    private function parse(string $path): DocumentNode
    {
        try {
            return Parser::parse($this->file->fileGetContents($path), ['noLocation' => true]);
        } catch (SyntaxError $e) {
            throw $this->error('%1', [$e->getMessage()], $path);
        }
    }

    /**
     * Add one object, enum or input definition; a name may be declared once
     *
     * @param array $definitions
     * @param array $files file each name was declared in
     * @param Node $node
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    private function define(array &$definitions, array &$files, Node $node, string $path): void
    {
        $name = $node->name->value;
        if (isset($files[$name])) {
            throw $this->error('%1 is declared in %2 too', [$name, $files[$name]], $path);
        }
        $files[$name] = $path;

        if ($node->kind === NodeKind::OBJECT_TYPE_DEFINITION) {
            $definitions['objects'][$name] = $this->object($node, $path);
        } elseif ($node->kind === NodeKind::ENUM_TYPE_DEFINITION) {
            $definitions['enums'][$name] = $this->enum($node);
        } else {
            $definitions['inputs'][$name] = ['fields' => $this->fields($node->fields)];
        }
    }

    /**
     * Apply one extend enum or extend type to what it extends, which must exist
     *
     * @param array $definitions
     * @param Node $node
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    private function applyExtension(array &$definitions, Node $node, string $path): void
    {
        $name = $node->name->value;
        if ($node->kind === NodeKind::ENUM_TYPE_EXTENSION) {
            if (!isset($definitions['enums'][$name])) {
                throw $this->error('extend enum %1: there is no such enum', [$name], $path);
            }
            array_push($definitions['enums'][$name]['values'], ...$this->enum($node)['values']);

            return;
        }
        if (!isset($definitions['objects'][$name])) {
            throw $this->error('extend type %1: there is no such type', [$name], $path);
        }
        $extension = $this->object($node, $path);
        $definitions['objects'][$name] = $this->extend($definitions['objects'][$name], $extension, $path);
    }

    /**
     * The .graphqls files of the enabled modules, in module sequence order
     *
     * @return string[]
     */
    private function files(): array
    {
        $files = [];
        foreach ($this->moduleList->getNames() as $module) {
            $dir = $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, $module) . '/components';
            if (!$this->file->isDirectory($dir)) {
                continue;
            }
            $paths = array_filter(
                $this->file->readDirectory($dir),
                static fn (string $path): bool => str_ends_with($path, '.graphqls')
            );
            sort($paths);
            array_push($files, ...$paths);
        }

        return $files;
    }

    /**
     * Apply one extend type: @component args replaced, repeatable directives appended, fields added or changed
     *
     * @param array $object
     * @param array $extension
     * @param string $path
     * @return array
     * @throws LocalizedException
     */
    private function extend(array $object, array $extension, string $path): array
    {
        $object['directives'] = $this->mergedDirectives($object['directives'], $extension['directives']);

        foreach ($extension['fields'] as $name => $field) {
            $exists = isset($object['fields'][$name]);
            $replace = isset($field['directives']['replace']);
            $remove = isset($field['directives']['remove']);

            if (($replace || $remove) && !$exists) {
                throw $this->error('%1.%2 is not there to replace or remove', [$object['name'], $name], $path);
            }
            if ($exists && !$replace && !$remove) {
                throw $this->error('%1.%2 exists: add @replace to change it', [$object['name'], $name], $path);
            }

            if ($remove) {
                unset($object['fields'][$name]);
            } else {
                unset($field['directives']['replace']);
                $object['fields'][$name] = $field;
            }
        }

        return $object;
    }

    /**
     * An extend type's directives over a type's: repeatable ones appended, the others' arguments replaced
     *
     * @param array $directives
     * @param array $extension
     * @return array
     */
    private function mergedDirectives(array $directives, array $extension): array
    {
        foreach ($extension as $directive => $args) {
            $directives[$directive] = \in_array($directive, self::REPEATABLE, true)
                ? [...($directives[$directive] ?? []), ...$args]
                : array_replace($directives[$directive] ?? [], $args);
        }

        return $directives;
    }

    /**
     * Fields of a type with @inherit applied: the parent's first, but those in except
     *
     * @param string $name
     * @param array $objects
     * @param string[] $seen
     * @return array
     * @throws LocalizedException
     */
    private function inheritedFields(string $name, array $objects, array $seen): array
    {
        $object = $objects[$name];
        $inherit = $object['directives']['inherit'] ?? null;
        if ($inherit === null) {
            return $object['fields'];
        }

        $parent = (string) ($inherit['from'] ?? '');
        if (!isset($objects[$parent]) || \in_array($parent, $seen, true)) {
            throw $this->error('%1 @inherit(from: %2): no such type, or a loop', [$name, $parent], $object['file']);
        }

        $fields = $this->inheritedFields($parent, $objects, [...$seen, $name]);
        foreach ((array) ($inherit['except'] ?? []) as $field) {
            unset($fields[$field]);
        }

        return array_replace($fields, $object['fields']);
    }

    /**
     * One pool entry, keys as di.xml had them
     *
     * @param array $args
     * @param string|null $description
     * @param array $fields
     * @param array $enums
     * @param array $inputs
     * @return array
     * @throws LocalizedException
     */
    private function component(array $args, ?string $description, array $fields, array $enums, array $inputs): array
    {
        $entry = [
            'component' => $args['template'],
            'group' => $args['group'] ?? null,
            'icon' => $args['icon'] ?? null,
            'provider' => $args['provider'] ?? null,
            'label' => $args['label'] ?? $args['code'],
            'description' => $description,
            'zones' => isset($args['zones']) ? array_combine($args['zones'], $args['zones']) : null,
            'available_on' => $args['availableOn'] ?? null,
            'hidden' => $args['hidden'] ?? null,
        ];

        foreach ($fields as $name => $field) {
            if (isset($field['directives']['slot'])) {
                $slot = $field['directives']['slot'];
                // `name` keeps a stored slot name that is no valid field name (product.info.additional)
                $entry['slots'][$slot['name'] ?? $name] = array_filter([
                    'label' => $slot['label'] ?? $name,
                    'allowed_groups' => isset($slot['allow']) ? array_combine($slot['allow'], $slot['allow']) : null,
                    'max_per_component' => $slot['max'] ?? null,
                ], static fn ($value): bool => $value !== null);
            } elseif (isset($field['directives']['setting'])) {
                $entry['settings'][$name] = $this->setting($field, $enums, $inputs);
            }
        }

        return array_filter($entry, static fn ($value): bool => $value !== null);
    }

    /**
     * A setting definition as the editor reads it
     *
     * @param array $field
     * @param array $enums
     * @param array $inputs
     * @return array
     * @throws LocalizedException
     */
    private function setting(array $field, array $enums, array $inputs): array
    {
        $args = $field['directives']['setting'] ?? [];

        $setting = [
            'label' => $args['label'] ?? '',
            'description' => $field['description'],
            'tab' => $args['tab'] ?? null,
        ] + $this->control($field, $args, $enums, $inputs);

        // A control the type does not pick, e.g. a field type added to the editor's SettingsPanel
        if (isset($args['control'])) {
            $setting['type'] = $args['control'];
        }

        return array_filter($setting + $this->limits($args), static fn ($value): bool => $value !== null);
    }

    /**
     * The control the field's type picks: array of an input, select of an enum or a source, or a scalar control
     *
     * @param array $field
     * @param array $args
     * @param array $enums
     * @param array $inputs
     * @return array
     * @throws LocalizedException
     */
    private function control(array $field, array $args, array $enums, array $inputs): array
    {
        $type = $field['type'];

        if ($field['list'] && isset($inputs[$type])) {
            $control = ['type' => 'array', 'labelField' => $args['labelField'] ?? null];
            foreach ($inputs[$type]['fields'] as $name => $sub) {
                $control['fields'][$name] = $this->setting($sub, $enums, $inputs);
            }

            return $control;
        }
        if (isset($enums[$type])) {
            $control = ['type' => 'select'];
            foreach ($enums[$type]['values'] as $value) {
                $control['options'][$value['value']] = ['value' => $value['value'], 'label' => $value['label']];
            }

            return $control;
        }
        if (isset($args['source'])) {
            return ['type' => 'select', 'source' => $args['source']];
        }
        if (isset(self::CONTROLS[$type])) {
            return ['type' => self::CONTROLS[$type]];
        }

        throw new LocalizedException(__('Setting "%1" has type %2, which no editor control shows', [
            $field['name'],
            $type,
        ]));
    }

    /**
     * The default, the number limits and the settings this one depends on
     *
     * @param array $args
     * @return array
     */
    private function limits(array $args): array
    {
        $limits = [];
        if (\array_key_exists('default', $args)) {
            $limits['default'] = $args['default'];
        }
        foreach (['min', 'max', 'step'] as $key) {
            if (isset($args[$key])) {
                $limits[$key] = $args[$key];
            }
        }
        foreach ((array) ($args['dependsOn'] ?? []) as $on => $values) {
            // One value stays a string; several are keyed by themselves, as di.xml wrote them
            $limits['depends'][$on] = \is_array($values) ? array_combine($values, $values) : $values;
        }

        return $limits;
    }

    /**
     * An object type or extension: description, directives by name, fields
     *
     * @param Node $node
     * @param string $path
     * @return array
     */
    private function object(Node $node, string $path): array
    {
        $directives = $this->directives($node);
        // The file, for errors
        foreach (self::REPEATABLE as $name) {
            foreach (array_keys($directives[$name] ?? []) as $i) {
                $directives[$name][$i]['file'] = $path;
            }
        }

        return [
            'name' => $node->name->value,
            'file' => $path,
            'description' => $node->kind === NodeKind::OBJECT_TYPE_DEFINITION ? $node->description?->value : null,
            'directives' => $directives,
            'fields' => $this->fields($node->fields),
        ];
    }

    /**
     * Enum values in declaration order, with the stored value and the editor label
     *
     * @param Node $node
     * @return array
     */
    private function enum(Node $node): array
    {
        $values = [];
        foreach ($node->values as $value) {
            $directives = $this->directives($value);
            $name = $value->name->value;
            // YES stores as "yes"; @value for one lowercase cannot give (16_9)
            $values[] = [
                'value' => (string) ($directives['value']['text'] ?? strtolower($name)),
                'label' => (string) ($directives['label']['text'] ?? $name),
            ];
        }

        return [
            'description' => $node->kind === NodeKind::ENUM_TYPE_DEFINITION ? $node->description?->value : null,
            'values' => $values,
        ];
    }

    /**
     * Fields by name: named type, list, nullability, description, directives
     *
     * @param iterable $nodes field or input value definitions
     * @return array
     */
    private function fields(iterable $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            $name = $node->name->value;
            $fields[$name] = ['name' => $name] + $this->type($node->type) + [
                'description' => $node->description?->value,
                'directives' => $this->directives($node),
            ];
        }

        return $fields;
    }

    /**
     * A field type unwrapped: `[[X!]!]!` → X, list depth 2, not nullable, innermost items not nullable
     *
     * @param Node $type
     * @return array{type: string, list: int, nullable: bool, itemNullable: bool}
     */
    private function type(Node $type): array
    {
        $nullable = $type->kind !== NodeKind::NON_NULL_TYPE;
        $type = $nullable ? $type : $type->type;
        $list = 0;
        $itemNullable = false;
        while ($type->kind === NodeKind::LIST_TYPE) {
            $list++;
            $type = $type->type;
            $itemNullable = $type->kind !== NodeKind::NON_NULL_TYPE;
            $type = $itemNullable ? $type : $type->type;
        }

        return [
            'type' => $type->name->value,
            'list' => $list,
            'nullable' => $nullable,
            'itemNullable' => $itemNullable,
        ];
    }

    /**
     * Directives of a node by name, arguments as plain PHP values; repeatable ones as lists
     *
     * @param Node $node
     * @return array<string, array>
     */
    private function directives(Node $node): array
    {
        $directives = [];
        foreach ($node->directives ?? [] as $directive) {
            $args = [];
            foreach ($directive->arguments as $argument) {
                $args[$argument->name->value] = AST::valueFromASTUntyped($argument->value);
            }
            if (\in_array($directive->name->value, self::REPEATABLE, true)) {
                $directives[$directive->name->value][] = $args;
            } else {
                $directives[$directive->name->value] = $args;
            }
        }

        return $directives;
    }

    /**
     * Field specs as the filter and the type writer need them: directives reduced to the optional flag
     *
     * @param array $entries
     * @return array
     */
    private function withoutDirectives(array $entries): array
    {
        foreach ($entries as &$entry) {
            foreach ($entry['fields'] as &$field) {
                $field['optional'] = isset($field['directives']['optional']);
                unset($field['directives'], $field['name']);
            }
        }

        return $entries;
    }

    /**
     * A schema error naming the file
     *
     * @param string $message
     * @param array $args
     * @param string $path
     * @return LocalizedException
     */
    private function error(string $message, array $args, string $path): LocalizedException
    {
        $args[] = $path;

        return new LocalizedException(__($message . ' (%' . \count($args) . ')', $args));
    }
}
