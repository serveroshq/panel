<?php

namespace App\Support\Machines;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Mcp\Tools\Machine\MachineGroups;
use App\Models\Machine;
use Illuminate\Support\Collection;

/**
 * Picks machines by what they are rather than by id: groups, labels,
 * provider, region, tier and whether they're online. Fields are ANDed;
 * a list of groups, or of values for one label, matches any of them.
 */
final class MachineSelector
{
    public const MAX_MACHINES = 200;

    private const FIELDS = ['groups', 'labels', 'provider', 'region', 'tier', 'online'];

    /** @return array<string, mixed> */
    public static function schema(string $description = 'Which machines, by what they are. Every field given must match. Give at least one.'): array
    {
        $oneOrMore = fn (string $description, int $maxLength) => [
            'type' => ['string', 'array'],
            'items' => ['type' => 'string', 'maxLength' => $maxLength],
            'maxItems' => 50,
            'maxLength' => $maxLength,
            'description' => $description,
        ];

        return [
            ...Schema::object([
                'groups' => $oneOrMore('Machines in this group, or in any of these (ids or names; "none" for machines in no group).', 120),
                'labels' => [
                    'type' => 'object',
                    'description' => 'Machines with these labels, like {"env": "prod"}. A list of values matches any of them: {"env": ["prod", "staging"]}. Every label given must match.',
                    'additionalProperties' => $oneOrMore('The value, or any of these values.', MachineMetadata::MAX_VALUE),
                ],
                'provider' => Schema::string('Machines at this provider, like Hetzner (any case).', maxLength: 120),
                'region' => Schema::string('Machines in this region (any case).', maxLength: 60),
                'tier' => Schema::string('Machines in this tier (any case).', maxLength: 60),
                'online' => Schema::boolean('true: only machines that are online now; false: only those that aren’t.'),
            ]),
            'description' => $description,
        ];
    }

    /**
     * For a tool that takes a list of machines in $field: a `selector` instead
     * becomes that list, as the ids of the machines it picks.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function expand(McpContext $context, array $arguments, string $field = 'machines'): array
    {
        $selector = $arguments['selector'] ?? null;
        unset($arguments['selector']);
        if ($selector === null) {
            return $arguments;
        }
        if (($arguments[$field] ?? null) !== null) {
            throw new ToolError("Invalid arguments: give a selector or {$field}, not both.");
        }
        $arguments[$field] = self::pick($context, ['selector' => $selector])->pluck('id')->all();

        return $arguments;
    }

    /**
     * The two ways to say which machines, for a tool's input schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function arguments(): array
    {
        return [
            'selector' => self::schema(),
            'machines' => Schema::array(Schema::machine('A machine: its id or name.'), 'These machines, by id or name, instead of a selector.', self::MAX_MACHINES),
        ];
    }

    /**
     * The machines a tool's `selector` or `machines` argument picks, by name.
     * Never every machine by default: nothing given, or nothing matching, is refused.
     *
     * @param  array<string, mixed>  $arguments
     * @return Collection<int, Machine>
     */
    public static function pick(McpContext $context, array $arguments): Collection
    {
        $selector = $arguments['selector'] ?? null;
        $machines = $arguments['machines'] ?? null;
        if ($selector !== null && $machines !== null) {
            throw new ToolError('Invalid arguments: give a selector or machines, not both.');
        }

        if ($machines !== null) {
            if (! is_array($machines) || $machines === [] || ! array_is_list($machines)) {
                throw new ToolError('Invalid arguments: machines must be a list of machine ids or names.');
            }
            if (count($machines) > self::MAX_MACHINES) {
                throw new ToolError('Invalid arguments: give at most '.self::MAX_MACHINES.' machines.');
            }

            return MachineGroups::machines($context, $machines);
        }

        if ($selector === null) {
            throw new ToolError('Invalid arguments: say which machines, with a selector or machines.');
        }

        $matched = self::resolve($context, self::parse($selector));
        if ($matched->isEmpty()) {
            throw new ToolError('No machines in '.$context->workspace->name.' match that selector. list_machines shows their groups, labels, providers, regions and tiers.');
        }

        return $matched;
    }

    /**
     * @return array{groups: list<string>|null, labels: array<string, list<string>>, provider: ?string, region: ?string, tier: ?string, online: ?bool}
     */
    public static function parse(mixed $selector): array
    {
        if (! is_array($selector) || ($selector !== [] && array_is_list($selector))) {
            throw new ToolError('Invalid arguments: the selector must be an object, like {"labels": {"env": "prod"}}.');
        }
        if (($unknown = array_diff(array_keys($selector), self::FIELDS)) !== []) {
            throw new ToolError('Invalid arguments: the selector doesn’t take '.implode(', ', $unknown).'. It takes '.implode(', ', self::FIELDS).'.');
        }
        $given = array_filter($selector, fn ($value) => $value !== null && $value !== [] && $value !== '');
        if ($given === []) {
            throw new ToolError('Invalid arguments: the selector needs at least one field, so it never means every machine by accident.');
        }

        $labels = [];
        if (isset($given['labels'])) {
            if (! is_array($given['labels']) || array_is_list($given['labels'])) {
                throw new ToolError('Invalid arguments: selector labels must be an object of label values, like {"env": "prod"}.');
            }
            foreach ($given['labels'] as $key => $values) {
                if (! MachineMetadata::validKey((string) $key)) {
                    throw new ToolError("Invalid arguments: “{$key}” isn’t a label name.");
                }
                $labels[(string) $key] = self::strings($values, "the label {$key}", MachineMetadata::MAX_VALUE);
            }
        }

        foreach (['provider' => 120, 'region' => 60, 'tier' => 60] as $field => $max) {
            if (isset($given[$field]) && (! is_string($given[$field]) || mb_strlen($given[$field]) > $max)) {
                throw new ToolError("Invalid arguments: the selector’s {$field} must be text of up to {$max} characters.");
            }
        }
        if (isset($given['online']) && ! is_bool($given['online'])) {
            throw new ToolError('Invalid arguments: the selector’s online must be true or false.');
        }

        return [
            'groups' => isset($given['groups']) ? self::strings($given['groups'], 'groups', 120) : null,
            'labels' => $labels,
            'provider' => $given['provider'] ?? null,
            'region' => $given['region'] ?? null,
            'tier' => $given['tier'] ?? null,
            'online' => $given['online'] ?? null,
        ];
    }

    /**
     * @param  array{groups: list<string>|null, labels: array<string, list<string>>, provider: ?string, region: ?string, tier: ?string, online: ?bool}  $selector
     * @return Collection<int, Machine>
     */
    public static function resolve(McpContext $context, array $selector): Collection
    {
        $query = $context->workspace->machines()->orderBy('name');

        if ($selector['groups'] !== null) {
            $none = collect($selector['groups'])->contains(fn (string $g) => mb_strtolower(trim($g)) === 'none');
            $ids = collect($selector['groups'])
                ->reject(fn (string $g) => mb_strtolower(trim($g)) === 'none')
                ->map(fn (string $g) => MachineGroups::find($context, $g)->id)
                ->all();
            $query->where(fn ($q) => $q->whereIn('machine_group_id', $ids)->when($none, fn ($q) => $q->orWhereNull('machine_group_id')));
        }

        return $query->get()
            ->filter(fn (Machine $m) => $selector['provider'] === null || MachineMetadata::matches($m, 'provider', $selector['provider']))
            ->filter(fn (Machine $m) => $selector['region'] === null || MachineMetadata::matches($m, 'region', $selector['region']))
            ->filter(fn (Machine $m) => $selector['tier'] === null || MachineMetadata::matches($m, 'tier', $selector['tier']))
            ->filter(fn (Machine $m) => collect($selector['labels'])->every(
                fn (array $values, string $key) => collect($values)->contains(fn (string $v) => MachineMetadata::matches($m, 'label:'.$key, $v)),
            ))
            ->filter(fn (Machine $m) => $selector['online'] === null || (Present::connection($m) === 'online') === $selector['online'])
            ->values();
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $what, int $max): array
    {
        $values = is_array($value) && array_is_list($value) ? $value : [$value];
        if ($values === [] || count($values) > 50) {
            throw new ToolError("Invalid arguments: {$what} takes one value, or a list of up to 50.");
        }
        foreach ($values as $v) {
            if (! is_string($v) || trim($v) === '' || mb_strlen($v) > $max) {
                throw new ToolError("Invalid arguments: each value of {$what} must be text of up to {$max} characters.");
            }
        }

        return $values;
    }
}
