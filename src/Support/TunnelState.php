<?php

namespace Phattarachai\DbTunnel\Support;

enum TunnelState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Conflict = 'conflict';
    case Unconfigured = 'no port';
}
