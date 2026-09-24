<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SshConfig
{
    private const int MAX_INCLUDE_DEPTH = 8;

    /** @var list<array{hosts: list<string>, forwards: list<int>}>|null */
    private ?array $blocks = null;

    public function __construct(public readonly string $path) {}

    public function defines(string $alias): bool
    {
        return collect($this->blocks())->contains(fn (array $block) => in_array($alias, $block['hosts'], true));
    }

    /**
     * @return list<int>
     */
    public function forwardsOf(string $alias): array
    {
        return collect($this->blocks())
            ->filter(fn (array $block) => in_array($alias, $block['hosts'], true))
            ->flatMap(fn (array $block) => $block['forwards'])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{port: int, alias: string}>
     */
    public function localForwards(): Collection
    {
        return collect($this->blocks())->flatMap(fn (array $block) => collect($block['forwards'])
            ->map(fn (int $port) => ['port' => $port, 'alias' => $block['hosts'][0] ?? '(Match block)']));
    }

    /**
     * @return list<array{hosts: list<string>, forwards: list<int>}>
     */
    public function blocks(): array
    {
        return $this->blocks ??= $this->parse($this->path, 0);
    }

    /**
     * @return list<array{hosts: list<string>, forwards: list<int>}>
     */
    private function parse(string $path, int $depth): array
    {
        if ($depth > self::MAX_INCLUDE_DEPTH || ! is_readable($path)) {
            return [];
        }

        $blocks = [['hosts' => [], 'forwards' => []]];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$keyword, $arguments] = $this->split($line);

            match ($keyword) {
                'host' => $blocks[] = ['hosts' => $arguments, 'forwards' => []],
                'match' => $blocks[] = ['hosts' => [], 'forwards' => []],
                'localforward' => $blocks[array_key_last($blocks)]['forwards'][] = $this->forwardPort($arguments[0] ?? ''),
                'include' => $blocks = [...$blocks, ...$this->includes($arguments, $depth), $this->continuation($blocks)],
                default => null,
            };
        }

        return $blocks;
    }

    /**
     * @param  list<array{hosts: list<string>, forwards: list<int>}>  $blocks
     * @return array{hosts: list<string>, forwards: list<int>}
     */
    private function continuation(array $blocks): array
    {
        return ['hosts' => $blocks[array_key_last($blocks)]['hosts'], 'forwards' => []];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function split(string $line): array
    {
        $line = trim(Str::before($line, '#'));
        $parts = preg_split('/[\s=]+/', $line, 2) ?: [];
        $arguments = preg_split('/\s+/', trim($parts[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [strtolower($parts[0] ?? ''), array_map(fn (string $argument) => trim($argument, '"'), $arguments)];
    }

    /**
     * @param  list<string>  $patterns
     * @return list<array{hosts: list<string>, forwards: list<int>}>
     */
    private function includes(array $patterns, int $depth): array
    {
        return collect($patterns)
            ->flatMap(fn (string $pattern) => glob($this->resolveInclude($pattern)) ?: [])
            ->flatMap(fn (string $file) => $this->parse($file, $depth + 1))
            ->values()
            ->all();
    }

    private function resolveInclude(string $pattern): string
    {
        $pattern = str_starts_with($pattern, '~/') ? Paths::home().substr($pattern, 1) : $pattern;

        return str_starts_with($pattern, '/') ? $pattern : dirname($this->path).'/'.$pattern;
    }

    private function forwardPort(string $spec): int
    {
        return (int) Str::afterLast($spec, ':');
    }
}
