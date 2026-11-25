<?php

declare(strict_types=1);

namespace Zokeibi\Generator;

use BackedEnum;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Pure PHP 8.5 Reflection DTO-to-UI Generator (`zokeibi form` & `zokeibi table`).
 *
 * Reflects any PHP 8.5 readonly DTO (including Kongono `#[Required]`, `#[Email]`, `#[Url]`,
 * `#[Date]`, `#[Min]`, `#[Max]`, `#[In]`, and `BackedEnum` properties) with ZERO runtime dependency
 * on `kongono/validation`, emitting accessible, strict-CSP Live 2.5 `.kgn.html` views.
 */
final readonly class DtoUiGenerator
{
    /** @var list<string> */
    private const array SENSITIVE_NAMES = ['password', 'passwordconfirmation', 'secret', 'token', 'apikey'];

    /** @var list<string> */
    private const array MULTILINE_NAMES = ['bio', 'description', 'body', 'content', 'notes', 'summary', 'message'];

    /**
     * Generates a complete strict-CSP Zokeibi `.kgn.html` form from a PHP 8.5 DTO class.
     *
     * @param class-string $dtoClass
     */
    public function generateForm(
        string $dtoClass,
        string $action = '/submit',
        string $method = 'POST',
        ?string $liveTarget = null,
        ?string $title = null,
    ): string {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException(sprintf('DTO class "%s" not found or not autoloaded.', $dtoClass));
        }

        $ref = new ReflectionClass($dtoClass);
        $fields = $this->extractFields($ref);
        $cardTitle = null !== $title && '' !== trim($title) ? trim($title) : $this->humanize($ref->getShortName());
        $httpMethod = strtoupper(trim($method));

        $formAttrs = sprintf('method="%s" action="%s" class="z-stack"', 'GET' === $httpMethod ? 'GET' : 'POST', $this->escAttr($action));
        if (null !== $liveTarget && '' !== trim($liveTarget)) {
            $kgnAttr = 'GET' === $httpMethod ? 'kgn-get' : 'kgn-post';
            $formAttrs .= sprintf(' %s="%s" kgn-target="%s" kgn-swap="morph"', $kgnAttr, $this->escAttr($action), $this->escAttr(trim($liveTarget)));
        }

        $lines = [
            sprintf('<x-ui.card title="%s" badge="Form">', $this->escAttr($cardTitle)),
            sprintf('  <form %s>', $formAttrs),
        ];

        if ('GET' !== $httpMethod) {
            $lines[] = '    <input type="hidden" name="_csrf" value="{{ $csrf ?? \'\' }}">';
        }
        if (!in_array($httpMethod, ['GET', 'POST'], true)) {
            $lines[] = sprintf('    <input type="hidden" name="_method" value="%s">', $this->escAttr($httpMethod));
        }

        foreach ($fields as $f) {
            $lines[] = '    ' . $this->renderFieldTag($f);
        }

        $lines[] = '    <div class="z-row">';
        $lines[] = '      <x-ui.button type="submit" variant="primary">Submit</x-ui.button>';
        $lines[] = '    </div>';
        $lines[] = '  </form>';
        $lines[] = '</x-ui.card>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generates a complete strict-CSP Zokeibi `.kgn.html` DataTable + EmptyState + Pagination view from a DTO class.
     *
     * @param class-string $dtoClass
     */
    public function generateTable(
        string $dtoClass,
        string $itemsVar = 'items',
        ?string $caption = null,
        ?string $baseUrl = null,
        ?string $liveTarget = null,
    ): string {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException(sprintf('DTO class "%s" not found or not autoloaded.', $dtoClass));
        }

        $ref = new ReflectionClass($dtoClass);
        $fields = array_values(array_filter(
            $this->extractFields($ref),
            static fn (array $f): bool => !$f['sensitive'],
        ));

        $cleanVar = preg_replace('/[^a-zA-Z0-9_]/', '', $itemsVar);
        $varName = is_string($cleanVar) && '' !== $cleanVar ? $cleanVar : 'items';
        $tableCaption = null !== $caption && '' !== trim($caption) ? trim($caption) : $this->humanize($ref->getShortName()) . ' List';

        $lines = [
            sprintf('<section id="%s-table" class="z-stack">', strtolower($ref->getShortName())),
            sprintf('  <x-ui.table caption="%s" searchable="true" sortable="true">', $this->escAttr($tableCaption)),
            '    <thead>',
            '      <tr>',
        ];

        foreach ($fields as $f) {
            $sortMode = ('int' === $f['type'] || 'float' === $f['type']) ? 'number' : 'string';
            $lines[] = sprintf('        <th scope="col" data-z-sort="%s">%s</th>', $sortMode, $this->escAttr($f['label']));
        }
        $lines[] = '      </tr>';
        $lines[] = '    </thead>';
        $lines[] = '    <tbody>';
        $lines[] = sprintf('      @forelse ($%s as $row)', $varName);
        $lines[] = '        <tr>';
        foreach ($fields as $f) {
            $expr = sprintf('$row->%s ?? ($row[\'%s\'] ?? \'\')', $f['name'], $f['name']);
            if ('bool' === $f['type']) {
                $lines[] = sprintf('          <td><x-ui.badge variant="emerald">{{ (%s) ? \'Yes\' : \'No\' }}</x-ui.badge></td>', $expr);
            } elseif ([] !== $f['options']) {
                $lines[] = sprintf('          <td><x-ui.badge variant="primary">{{ %s instanceof \\BackedEnum ? %s->value : (string) (%s) }}</x-ui.badge></td>', $expr, $expr, $expr);
            } else {
                $lines[] = sprintf('          <td>{{ %s }}</td>', $expr);
            }
        }
        $lines[] = '        </tr>';
        $lines[] = '      @empty';
        $lines[] = sprintf('        <tr><td colspan="%d"><x-ui.empty-state title="No records yet" description="New %s entries will appear here." /></td></tr>', max(1, count($fields)), $this->escAttr(strtolower($tableCaption)));
        $lines[] = '      @endforelse';
        $lines[] = '    </tbody>';
        $lines[] = '  </x-ui.table>';

        if (null !== $baseUrl && '' !== trim($baseUrl)) {
            $liveAttr = null !== $liveTarget && '' !== trim($liveTarget)
                ? sprintf(' live-target="%s"', $this->escAttr(trim($liveTarget)))
                : '';
            $lines[] = sprintf(
                '  <x-ui.pagination current="{{ $page ?? 1 }}" total="{{ $totalPages ?? 1 }}" base-url="%s"%s />',
                $this->escAttr(trim($baseUrl)),
                $liveAttr,
            );
        }

        $lines[] = '</section>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param ReflectionClass<object> $ref
     * @return list<array{
     *     name: string,
     *     label: string,
     *     type: string,
     *     required: bool,
     *     sensitive: bool,
     *     multiline: bool,
     *     inputType: string,
     *     options: array<string, string>,
     *     hint: string
     * }>
     */
    private function extractFields(ReflectionClass $ref): array
    {
        $ctor = $ref->getConstructor();
        $sources = null !== $ctor && [] !== $ctor->getParameters()
            ? $ctor->getParameters()
            : $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $fields = [];
        foreach ($sources as $src) {
            $name = $src->getName();
            $type = $src->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
            $allowsNull = null === $type || $type->allowsNull();
            $hasDefault = $src instanceof ReflectionParameter ? $src->isDefaultValueAvailable() : $src->hasDefaultValue();

            /** @var list<ReflectionAttribute<object>> $attrs */
            $attrs = $src->getAttributes();
            $attrMap = [];
            foreach ($attrs as $attr) {
                $tail = strrchr('\\' . $attr->getName(), '\\');
                $short = false !== $tail ? substr($tail, 1) : $attr->getName();
                $attrMap[$short] = $attr->getArguments();
            }

            $required = isset($attrMap['Required']) || (!$allowsNull && !$hasDefault && 'bool' !== $typeName);
            $lowerName = strtolower($name);
            $sensitive = in_array($lowerName, self::SENSITIVE_NAMES, true) || str_contains($lowerName, 'password') || str_contains($lowerName, 'secret');
            $multiline = in_array($lowerName, self::MULTILINE_NAMES, true);

            $options = [];
            if (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)) {
                foreach ($typeName::cases() as $case) {
                    $options[(string) $case->value] = $this->humanize($case->name);
                }
            } elseif (isset($attrMap['In'][0]) && is_array($attrMap['In'][0])) {
                foreach ($attrMap['In'][0] as $opt) {
                    if (is_string($opt) || is_int($opt)) {
                        $options[(string) $opt] = $this->humanize((string) $opt);
                    }
                }
            }

            $inputType = match (true) {
                $sensitive => 'password',
                isset($attrMap['Email']) => 'email',
                isset($attrMap['Url']) => 'url',
                isset($attrMap['Date']) => 'date',
                'int' === $typeName || 'float' === $typeName => 'number',
                default => 'text',
            };

            $hints = [];
            if (isset($attrMap['Min'][0]) && is_scalar($attrMap['Min'][0])) {
                $hints[] = 'Min ' . (string) $attrMap['Min'][0];
            }
            if (isset($attrMap['Max'][0]) && is_scalar($attrMap['Max'][0])) {
                $hints[] = 'Max ' . (string) $attrMap['Max'][0];
            }
            if (isset($attrMap['Uuid'])) {
                $hints[] = 'UUID format';
            }

            $fields[] = [
                'name' => $name,
                'label' => $this->humanize($name),
                'type' => $typeName,
                'required' => $required,
                'sensitive' => $sensitive,
                'multiline' => $multiline,
                'inputType' => $inputType,
                'options' => $options,
                'hint' => implode(' · ', $hints),
            ];
        }

        return $fields;
    }

    /**
     * @param array{
     *     name: string,
     *     label: string,
     *     type: string,
     *     required: bool,
     *     sensitive: bool,
     *     multiline: bool,
     *     inputType: string,
     *     options: array<string, string>,
     *     hint: string
     * } $f
     */
    private function renderFieldTag(array $f): string
    {
        $req = $f['required'] ? ' required="true"' : '';
        $name = $this->escAttr($f['name']);
        $label = $this->escAttr($f['label']);

        if ('bool' === $f['type']) {
            return sprintf('<x-ui.switch name="%s" label="%s" />', $name, $label);
        }

        if ('date' === $f['inputType']) {
            return sprintf('<x-ui.datepicker name="%s" label="%s" value="@old(\'%s\')"%s />', $name, $label, $name, $req);
        }

        if ([] !== $f['options']) {
            if ('array' === $f['type']) {
                $pairs = [];
                foreach ($f['options'] as $k => $v) {
                    $pairs[] = $k . ':' . $v;
                }

                return sprintf('<x-ui.multi-select name="%s" label="%s" options="%s" />', $name, $label, $this->escAttr(implode('|', $pairs)));
            }
            if (count($f['options']) > 6) {
                $pairs = [];
                foreach ($f['options'] as $k => $v) {
                    $pairs[] = $k . ':' . $v;
                }

                return sprintf('<x-ui.combobox name="%s" label="%s" options="%s" value="@old(\'%s\')"%s />', $name, $label, $this->escAttr(implode('|', $pairs)), $name, $req);
            }

            $opts = [];
            foreach ($f['options'] as $k => $v) {
                $opts[] = sprintf('<option value="%s">%s</option>', $this->escAttr($k), $this->escAttr($v));
            }

            return sprintf('<x-ui.select name="%s" label="%s"%s>%s</x-ui.select>', $name, $label, $req, implode('', $opts));
        }

        if ($f['multiline']) {
            if ('content' === strtolower($f['name']) || 'body' === strtolower($f['name'])) {
                return sprintf('<x-ui.editor name="%s" label="%s" rows="6" />', $name, $label);
            }

            return sprintf('<x-ui.textarea name="%s" label="%s" rows="3"%s>@old(\'%s\')</x-ui.textarea>', $name, $label, $req, $name);
        }

        $valAttr = $f['sensitive'] ? '' : sprintf(' value="@old(\'%s\')"', $name);
        $hintAttr = '' !== $f['hint'] ? sprintf(' hint="%s"', $this->escAttr($f['hint'])) : '';

        return sprintf(
            '<x-ui.input name="%s" type="%s" label="%s"%s%s%s />',
            $name,
            $this->escAttr($f['inputType']),
            $label,
            $valAttr,
            $hintAttr,
            $req,
        );
    }

    private function humanize(string $identifier): string
    {
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $identifier);
        $spaced = str_replace(['_', '-'], ' ', $spaced);

        return ucwords(trim($spaced));
    }

    private function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
