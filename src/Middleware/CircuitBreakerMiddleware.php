<?php

namespace Fuse\Middleware;

use Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CircuitBreakerMiddleware
{
    private string $service;

    public function __construct(string $service)
    {
        $this->service = $service;
    }

    public function handle(mixed $job, callable $next): mixed
    {
        if (!$this->isEnabled()) {
            return $next($job);
        }

        $breaker = new CircuitBreaker($this->service);

        if ($breaker->isOpen()) {
            return $job->release(10);
        }

        if ($breaker->isHalfOpen()) {
            return $this->handleHalfOpen($job, $next, $breaker);
        }

        try {
            $result = $next($job);
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure($e);
            throw $e;
        }
    }

    private function handleHalfOpen(mixed $job, callable $next, CircuitBreaker $breaker): mixed
    {
        $lock = Cache::lock($breaker->key('probe'), 5);

        if (!$lock->get()) {
            return $job->release(10);
        }

        try {
            $result = $next($job);
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure($e);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function isEnabled(): bool
    {
        $cacheValue = Cache::get('fuse:enabled');

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
