<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PortInspector
{
    public function listener(int $port): ?Listener
    {
        $listener = $this->listeners($port)->first();

        return $listener?->withArguments($this->arguments($listener->pid));
    }

    /**
     * @return Collection<int, Listener>
     */
    public function listeners(?int $port = null): Collection
    {
        return match ($this->probe()) {
            'ss' => $this->parseSs(Process::run($this->ssCommand($port))->output()),
            default => $this->parseLsof(Process::run($this->lsofCommand($port))->output()),
        };
    }

    public function arguments(int $pid): string
    {
        return trim(Process::run(['ps', '-o', 'args=', '-p', (string) $pid])->output());
    }

    private function probe(): string
    {
        $probe = config('db-tunnel.probe', 'auto');

        if ($probe !== 'auto') {
            return $probe;
        }

        return PHP_OS_FAMILY === 'Darwin' ? 'lsof' : 'ss';
    }

    /**
     * @return list<string>
     */
    private function lsofCommand(?int $port): array
    {
        return ['lsof', '-nP', $port ? "-iTCP:{$port}" : '-iTCP', '-sTCP:LISTEN', '-Fpcn'];
    }

    /**
     * @return list<string>
     */
    private function ssCommand(?int $port): array
    {
        return $port ? ['ss', '-ltnpH', 'sport', '=', ":{$port}"] : ['ss', '-ltnpH'];
    }

    /**
     * @return Collection<int, Listener>
     */
    private function parseLsof(string $output): Collection
    {
        $listeners = collect();
        $pid = 0;
        $command = '';

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $value = substr($line, 1);

            match ($line[0] ?? '') {
                'p' => $pid = (int) $value,
                'c' => $command = $value,
                'n' => $listeners->put($this->portOf($value), new Listener($this->portOf($value), $pid, $command)),
                default => null,
            };
        }

        return $listeners;
    }

    /**
     * @return Collection<int, Listener>
     */
    private function parseSs(string $output): Collection
    {
        return collect(preg_split('/\R/', trim($output)) ?: [])
            ->filter()
            ->map(fn (string $line) => $this->ssListener($line))
            ->keyBy('port');
    }

    private function ssListener(string $line): Listener
    {
        $columns = preg_split('/\s+/', trim($line)) ?: [];
        preg_match('/users:\(\("([^"]+)",pid=(\d+)/', $line, $user);

        return new Listener($this->portOf($columns[3] ?? ''), (int) ($user[2] ?? 0), $user[1] ?? '?');
    }

    private function portOf(string $address): int
    {
        return (int) Str::afterLast($address, ':');
    }
}
