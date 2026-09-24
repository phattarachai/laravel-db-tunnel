<?php

namespace Phattarachai\DbTunnel\Support;

class EnvFile
{
    public function __construct(public readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        return (bool) preg_match($this->pattern($key), $this->contents());
    }

    public function set(string $key, string $value): void
    {
        $contents = $this->contents();

        if ($this->has($key)) {
            file_put_contents($this->path, preg_replace($this->pattern($key), "{$key}={$value}", $contents, 1));

            return;
        }

        file_put_contents($this->path, $this->withTrailingNewline($contents)."{$key}={$value}\n");
    }

    public function ensure(string $key, string $value = ''): bool
    {
        if (! $this->exists() || $this->has($key)) {
            return false;
        }

        $this->set($key, $value);

        return true;
    }

    private function pattern(string $key): string
    {
        return '/^'.preg_quote($key, '/').'=.*$/m';
    }

    private function contents(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path) : '';
    }

    private function withTrailingNewline(string $contents): string
    {
        return $contents === '' || str_ends_with($contents, "\n") ? $contents : "{$contents}\n";
    }
}
