<?php

namespace Fuse;

use Fuse\Enums\CircuitState;
use Fuse\Events\CircuitBreakerClosed;
use Fuse\Events\CircuitBreakerHalfOpen;
use Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class CircuitBreaker
{
    private function getFailureThreshold(): int
    {
        return ThresholdCalculator::for($this->serviceName);
    }

    private function getTimeout(): int
    {
        $config = config("fuse.services.{$this->serviceName}", []);

        return $config['timeout'] ?? config('fuse.default_timeout', 60);
    }

    private function getMinRequests(): int
    {
        $config = config("fuse.services.{$this->serviceName}", []);

        return $config['min_requests'] ?? config('fuse.default_min_requests', 10);
    }

    public function __construct(
        private readonly string $serviceName
    ) {
    }

    public function isOpen(): bool
    {
        if ($this->getState() !== CircuitState::Open) {
            return false;
        }

        $openedAt = Cache::get($this->key('opened_at'));

        if ($openedAt && (time() - $openedAt) >= $this->getTimeout()) {
            $this->transitionTo(CircuitState::HalfOpen);

            return false;
        }

        return true;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HalfOpen;
    }

    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::Closed;
    }

    public function recordSuccess(): void
    {
        $this->incrementAttempts();

        $state = $this->getState();

        if ($state === CircuitState::Open) {
            $openedAt = Cache::get($this->key('opened_at'));

            if ($openedAt && (time() - $openedAt) >= $this->getTimeout()) {
                $this->transitionTo(CircuitState::HalfOpen);
            } else {
                $this->releaseLock('probe');
                return;
            }
        }

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->transitionTo(CircuitState::Closed);
        }

        $this->releaseLock('probe');
    }

    public function recordFailure(?Throwable $exception = null): void
    {
        if ($exception !== null && !$this->shouldCountFailure($exception)) {
            $this->incrementAttempts();

            return;
        }

        $state = $this->getState();

        if ($state === CircuitState::Open) {
            $openedAt = Cache::get($this->key('opened_at'));

            if ($openedAt && (time() - $openedAt) >= $this->getTimeout()) {
                $this->transitionTo(CircuitState::HalfOpen);
            } else {
                return;
            }
        }

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->transitionTo(CircuitState::Open, 100, 1, 1);
            $this->releaseLock('probe');

            return;
        }

        $window = $this->getCurrentWindow();
        $attemptsKey = $this->key("attempts:{$window}");
        $failuresKey = $this->key("failures:{$window}");

        $attempts = (int) Cache::increment($attemptsKey);
        $failures = (int) Cache::increment($failuresKey);

        Cache::put($attemptsKey, $attempts, 120);
        Cache::put($failuresKey, $failures, 120);

        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        if ($attempts >= $this->getMinRequests() && $failureRate >= $this->getFailureThreshold()) {
            $this->transitionTo(CircuitState::Open, $failureRate, $attempts, $failures);
        }
    }

    private function shouldCountFailure(Throwable $e): bool
    {
        if ($e instanceof TooManyRequestsHttpException) {
            return false;
        }

        if ($e instanceof ClientException) {
            $statusCode = $e->getResponse()?->getStatusCode();

            return !in_array($statusCode, [401, 403, 429], true);
        }

        return true;
    }

    public function getState(): CircuitState
    {
        $state = Cache::get($this->key('state'), CircuitState::Closed->value);

        return CircuitState::from($state);
    }

    public function getStats(): array
    {
        $window = $this->getCurrentWindow();
        $attempts = (int) Cache::get($this->key("attempts:{$window}"), 0);
        $failures = (int) Cache::get($this->key("failures:{$window}"), 0);
        $openedAt = Cache::get($this->key('opened_at'));
        $state = $this->getState();

        return [
            'state' => $state->value,
            'attempts' => $attempts,
            'failures' => $failures,
            'failure_rate' => $attempts > 0 ? round(($failures / $attempts) * 100, 1) : 0,
            'opened_at' => $openedAt,
            'recovery_at' => $openedAt ? $openedAt + $this->getTimeout() : null,
            'timeout' => $this->getTimeout(),
            'threshold' => $this->getFailureThreshold(),
            'min_requests' => $this->getMinRequests(),
        ];
    }

    public function reset(): void
    {
        $window = $this->getCurrentWindow();

        Cache::forget($this->key('state'));
        Cache::forget($this->key('opened_at'));
        Cache::forget($this->key("attempts:{$window}"));
        Cache::forget($this->key("failures:{$window}"));
        $this->releaseLock('probe');
        $this->releaseLock('transition');
    }

    private function transitionTo(
        CircuitState $newState,
        float $failureRate = 0,
        int $attempts = 0,
        int $failures = 0
    ): void {
        $lockKey = $this->key('transition');

        $lock = Cache::lock($lockKey, 5);

        if (!$lock->get()) {
            return;
        }

        try {
            if ($this->getState() === $newState) {
                return;
            }

            Cache::put($this->key('state'), $newState->value);

            if ($newState === CircuitState::Open) {
                Cache::put($this->key('opened_at'), time());
            }

            if ($newState === CircuitState::Closed) {
                Cache::forget($this->key('opened_at'));
            }

            match ($newState) {
                CircuitState::Open => event(new CircuitBreakerOpened(
                    $this->serviceName,
                    $failureRate,
                    $attempts,
                    $failures
                )),
                CircuitState::HalfOpen => event(new CircuitBreakerHalfOpen($this->serviceName)),
                CircuitState::Closed => event(new CircuitBreakerClosed($this->serviceName)),
            };
        } finally {
            $lock->release();
        }
    }

    private function incrementAttempts(): void
    {
        $window = $this->getCurrentWindow();
        $key = $this->key("attempts:{$window}");

        $attempts = (int) Cache::increment($key);
        Cache::put($key, $attempts, 120);
    }

    private function getCurrentWindow(): string
    {
        return now()->format('YmdHi');
    }

    public function key(string $suffix): string
    {
        return "fuse:{$this->serviceName}:{$suffix}";
    }

    private function releaseLock(string $suffix): void
    {
        $lockKey = $this->key($suffix);
        Cache::lock($lockKey)->forceRelease();
    }
}
