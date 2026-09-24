<?php

namespace Phattarachai\DbTunnel;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Phattarachai\DbTunnel\Exceptions\TunnelException;
use Phattarachai\DbTunnel\Support\Tunnel;
use Phattarachai\DbTunnel\Support\TunnelState;

class Supervisor
{
    private const int SIGTERM = 15;

    private bool $stopping = false;

    private ?InvokedProcess $process = null;

    public function __construct(private TunnelManager $manager) {}

    /**
     * @param  Closure(string): void  $log
     */
    public function run(Tunnel $tunnel, Closure $log, ?int $cycles = null): void
    {
        while (! $this->stopping && ($cycles === null || $cycles-- > 0)) {
            $this->cycle($tunnel, $log);
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
        $this->process?->signal(self::SIGTERM);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function cycle(Tunnel $tunnel, Closure $log): void
    {
        $status = $this->manager->status($tunnel);

        if ($status->state === TunnelState::Conflict && $status->listener) {
            throw TunnelException::portHeld($tunnel, $status->listener);
        }

        if ($status->isOpen()) {
            Sleep::for(5)->seconds();

            return;
        }

        $this->dial($tunnel, $log);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function dial(Tunnel $tunnel, Closure $log): void
    {
        $log("dialing {$tunnel->alias} for 127.0.0.1:{$tunnel->localPort}…");
        $this->process = Process::forever()->start($this->manager->command($tunnel, detached: false));

        $this->awaitEstablished($tunnel)
            ? $this->holdOpen($tunnel, $log)
            : $this->abandon($tunnel, $log);

        $this->process = null;
        $this->pauseBeforeRedial();
    }

    private function awaitEstablished(Tunnel $tunnel): bool
    {
        $attempts = max(1, intdiv((int) config('db-tunnel.watch.establish_timeout', 90), 2));

        while ($attempts-- > 0 && $this->process?->running() && ! $this->stopping) {
            if ($this->manager->status($tunnel)->isOpen()) {
                return true;
            }

            Sleep::for(2)->seconds();
        }

        return false;
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function holdOpen(Tunnel $tunnel, Closure $log): void
    {
        $log("up — 127.0.0.1:{$tunnel->localPort} → {$tunnel->remote()}");

        while ($this->process?->running() && ! $this->stopping) {
            Sleep::for(1)->seconds();
        }

        $this->stopping || $log('tunnel dropped — re-dialing');
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function abandon(Tunnel $tunnel, Closure $log): void
    {
        $running = (bool) $this->process?->running();
        $this->process?->signal(self::SIGTERM);

        if ($this->stopping) {
            return;
        }

        $log($running
            ? "handshake with {$tunnel->alias} stalled — killed the dial, retrying"
            : "ssh {$tunnel->alias} exited: ".(trim((string) $this->process?->errorOutput()) ?: 'no output'));
    }

    private function pauseBeforeRedial(): void
    {
        $this->stopping || Sleep::for((int) config('db-tunnel.watch.redial_delay', 3))->seconds();
    }
}
