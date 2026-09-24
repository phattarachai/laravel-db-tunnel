<?php

namespace Phattarachai\DbTunnel;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Phattarachai\DbTunnel\Exceptions\TunnelException;
use Phattarachai\DbTunnel\Support\PortInspector;
use Phattarachai\DbTunnel\Support\SshConfig;
use Phattarachai\DbTunnel\Support\Tunnel;
use Phattarachai\DbTunnel\Support\TunnelState;
use Phattarachai\DbTunnel\Support\TunnelStatus;

class TunnelManager
{
    public function __construct(
        private PortInspector $inspector,
        private SshConfig $sshConfig,
    ) {}

    public function status(Tunnel $tunnel): TunnelStatus
    {
        if (! $tunnel->hasPort()) {
            return new TunnelStatus(TunnelState::Unconfigured);
        }

        $listener = $this->inspector->listener($tunnel->localPort);

        return match (true) {
            $listener === null => new TunnelStatus(TunnelState::Closed),
            $listener->isSshTo($tunnel->alias) => new TunnelStatus(TunnelState::Open, $listener),
            default => new TunnelStatus(TunnelState::Conflict, $listener),
        };
    }

    public function open(Tunnel $tunnel): TunnelStatus
    {
        $status = $this->status($tunnel);

        if ($status->isOpen()) {
            return $status;
        }

        $this->guardOpenable($tunnel, $status);
        $this->dial($tunnel);

        return $this->awaitOpen($tunnel);
    }

    public function close(Tunnel $tunnel): bool
    {
        $status = $this->status($tunnel);

        if (! $status->isOpen()) {
            return false;
        }

        return Process::run(['kill', (string) $status->listener?->pid])->successful();
    }

    /**
     * @return list<string>
     */
    public function command(Tunnel $tunnel, bool $detached): array
    {
        return [
            'ssh',
            ...($detached ? ['-f'] : []),
            '-N',
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ControlMaster=no',
            '-o', 'ControlPath=none',
            '-o', 'ServerAliveInterval='.config('db-tunnel.keepalive.interval', 15),
            '-o', 'ServerAliveCountMax='.config('db-tunnel.keepalive.count_max', 8),
            ...$this->forwardArguments($tunnel),
            $tunnel->alias,
        ];
    }

    public function waitUntilOpen(Tunnel $tunnel, int $seconds): bool
    {
        foreach (range(1, max(1, $seconds * 4)) as $attempt) {
            if ($this->status($tunnel)->isOpen()) {
                return true;
            }

            Sleep::for(250)->milliseconds();
        }

        return $this->status($tunnel)->isOpen();
    }

    private function guardOpenable(Tunnel $tunnel, TunnelStatus $status): void
    {
        throw_if($status->state === TunnelState::Unconfigured, TunnelException::noPort($tunnel));
        if ($status->state === TunnelState::Conflict && $status->listener) {
            throw TunnelException::portHeld($tunnel, $status->listener);
        }
    }

    private function dial(Tunnel $tunnel): void
    {
        $result = Process::timeout($this->connectTimeout())->run($this->command($tunnel, detached: true));

        throw_if($result->failed(), TunnelException::sshFailed($tunnel, $result->errorOutput()));
    }

    private function awaitOpen(Tunnel $tunnel): TunnelStatus
    {
        $seconds = (int) config('db-tunnel.wait', 10);

        throw_unless($this->waitUntilOpen($tunnel, $seconds), TunnelException::neverListened($tunnel, $seconds));

        return $this->status($tunnel);
    }

    /**
     * @return list<string>
     */
    private function forwardArguments(Tunnel $tunnel): array
    {
        if (in_array($tunnel->localPort, $this->sshConfig->forwardsOf($tunnel->alias), true)) {
            return [];
        }

        return ['-L', $tunnel->forward()];
    }

    private function connectTimeout(): int
    {
        return (int) config('db-tunnel.connect_timeout', 30);
    }
}
