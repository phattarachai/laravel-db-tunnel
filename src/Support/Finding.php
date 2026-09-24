<?php

namespace Phattarachai\DbTunnel\Support;

final readonly class Finding
{
    public const string OK = 'ok';

    public const string WARN = 'warn';

    public const string FAIL = 'fail';

    public function __construct(
        public string $level,
        public string $subject,
        public string $message,
    ) {}

    public static function ok(string $subject, string $message): self
    {
        return new self(self::OK, $subject, $message);
    }

    public static function warn(string $subject, string $message): self
    {
        return new self(self::WARN, $subject, $message);
    }

    public static function fail(string $subject, string $message): self
    {
        return new self(self::FAIL, $subject, $message);
    }
}
