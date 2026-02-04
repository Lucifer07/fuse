<?php

namespace Fuse\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitBreakerOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $service,
        public readonly float $failureRate,
        public readonly int $attempts,
        public readonly int $failures
    ) {
    }
}
