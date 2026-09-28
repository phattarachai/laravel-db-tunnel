<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Str;

/**
 * A GCE instance whose port 22 is closed to the internet and reached only through Identity-Aware Proxy.
 * The tunnel stays a plain `ssh` process; IAP rides in as its ProxyCommand, with the key gcloud manages.
 */
final readonly class GcpIap
{
    public function __construct(
        public string $instance,
        public ?string $project = null,
        public ?string $zone = null,
    ) {}

    public static function identityFile(): string
    {
        return config('db-tunnel.gcp_iap.identity_file') ?: Paths::home().'/.ssh/google_compute_engine';
    }

    /**
     * @return list<string>
     */
    public function sshOptions(): array
    {
        return [
            '-o', 'ProxyCommand='.$this->proxyCommand(),
            '-o', 'IdentityFile='.self::identityFile(),
            '-o', 'StrictHostKeyChecking=accept-new',
        ];
    }

    public function proxyCommand(): string
    {
        return "gcloud compute start-iap-tunnel {$this->instance} 22 --listen-on-stdin{$this->locationFlags()} --verbosity=error";
    }

    /**
     * The one-time interactive login that creates the gcloud key and publishes it to the instance metadata.
     */
    public function loginCommand(): string
    {
        return "gcloud compute ssh {$this->instance}{$this->locationFlags()} --tunnel-through-iap";
    }

    public function hintFor(string $error): ?string
    {
        return match (true) {
            Str::contains($error, ['gcloud auth login', 'Reauthentication', 'refreshing your current auth tokens'], ignoreCase: true) => 'gcloud needs a fresh login — run `gcloud auth login`, then retry.',
            Str::contains($error, 'Permission denied (publickey)') => "your gcloud key is not on {$this->instance} yet — run `{$this->loginCommand()}` once; it creates ".self::identityFile().' and publishes it.',
            Str::contains($error, ['gcloud: command not found', 'gcloud: not found', 'No such file or directory']) => 'the gcloud CLI is not on PATH — install the Google Cloud SDK.',
            Str::contains($error, ['4033', 'not authorized'], ignoreCase: true) => "your account may lack IAP-secured Tunnel User on {$this->instance}.",
            default => null,
        };
    }

    private function locationFlags(): string
    {
        return ($this->project ? " --project={$this->project}" : '').($this->zone ? " --zone={$this->zone}" : '');
    }
}
