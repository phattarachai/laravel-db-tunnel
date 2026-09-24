<?php

namespace Phattarachai\DbTunnel\Support;

use Closure;
use Illuminate\Support\Facades\Date;

class PortRegistry
{
    public function __construct(public readonly string $path) {}

    /**
     * @return array<int, array{project: string, connection: string, alias: string, allocated_at: string}>
     */
    public function all(): array
    {
        if (! is_readable($this->path)) {
            return [];
        }

        return $this->decode((string) file_get_contents($this->path));
    }

    public function portFor(string $project, string $connection): ?int
    {
        return collect($this->all())
            ->filter(fn (array $entry) => $entry['project'] === $project && $entry['connection'] === $connection)
            ->keys()
            ->first();
    }

    public function claim(int $port, string $project, string $connection, string $alias): void
    {
        $this->mutate(fn (array $ports) => collect($ports)
            ->reject(fn (array $entry) => $entry['project'] === $project && $entry['connection'] === $connection)
            ->put($port, [
                'project' => $project,
                'connection' => $connection,
                'alias' => $alias,
                'allocated_at' => Date::now()->toIso8601String(),
            ])
            ->sortKeys()
            ->all());
    }

    /**
     * @return list<int>
     */
    public function prune(): array
    {
        $stale = collect($this->all())
            ->reject(fn (array $entry) => is_dir($entry['project']))
            ->keys()
            ->all();

        $this->mutate(fn (array $ports) => array_diff_key($ports, array_flip($stale)));

        return $stale;
    }

    /**
     * @param  Closure(array<int, array{project: string, connection: string, alias: string, allocated_at: string}>): array<int, array{project: string, connection: string, alias: string, allocated_at: string}>  $change
     */
    private function mutate(Closure $change): void
    {
        $this->ensureDirectory();
        $handle = fopen($this->path, 'c+');
        flock($handle, LOCK_EX);

        $ports = $change($this->decode((string) stream_get_contents($handle)));

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['ports' => (object) $ports], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @return array<int, array{project: string, connection: string, alias: string, allocated_at: string}>
     */
    private function decode(string $json): array
    {
        return (array) (json_decode($json, true)['ports'] ?? []);
    }

    private function ensureDirectory(): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0700, true);
        }
    }
}
