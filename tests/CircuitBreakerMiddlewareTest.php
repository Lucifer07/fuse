<?php

use Fuse\CircuitBreaker;
use Fuse\Middleware\CircuitBreakerMiddleware;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->breaker = new CircuitBreaker('test-service');
    $this->breaker->reset();
    $this->middleware = new CircuitBreakerMiddleware('test-service');
    $this->job = new class {
        public function release(int $delay)
        {
            $this->released = true;
            $this->releaseDelay = $delay;
        }
    };
});

it('executes job when circuit is closed', function () {
    $executed = false;
    $next = function () use (&$executed) {
        $executed = true;
        return 'success';
    };

    $result = $this->middleware->handle($this->job, $next);

    expect($executed)->toBeTrue();
    expect($result)->toBe('success');
});

it('releases job when circuit is open', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    $executed = false;
    $next = function () use (&$executed) {
        $executed = true;
        return 'success';
    };

    $this->middleware->handle($this->job, $next);

    expect($executed)->toBeFalse();
    expect($this->job->released)->toBeTrue();
    expect($this->job->releaseDelay)->toBe(10);
});

it('records success when job succeeds in closed state', function () {
    $next = function () {
        return 'success';
    };

    $this->middleware->handle($this->job, $next);

    $stats = $this->breaker->getStats();

    expect($stats['attempts'])->toBe(1);
    expect($stats['failures'])->toBe(0);
});

it('records failure when job fails in closed state', function () {
    $exception = new Exception('Test error');
    $next = function () use ($exception) {
        throw $exception;
    };

    try {
        $this->middleware->handle($this->job, $next);
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('Test error');
    }

    $stats = $this->breaker->getStats();

    expect($stats['attempts'])->toBe(1);
    expect($stats['failures'])->toBe(1);
});

it('allows probe request when circuit is half-open', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    $executed = false;
    $next = function () use (&$executed) {
        $executed = true;
        return 'success';
    };

    $result = $this->middleware->handle($this->job, $next);

    expect($executed)->toBeTrue();
    expect($result)->toBe('success');
    expect($this->breaker->isClosed())->toBeTrue();
});

it('releases job when probe is already in progress', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    $lock = Cache::lock($this->breaker->key('probe'), 5);
    $lock->get();

    $executed = false;
    $next = function () use (&$executed) {
        $executed = true;
        return 'success';
    };

    $this->middleware->handle($this->job, $next);

    expect($executed)->toBeFalse();
    expect($this->job->released)->toBeTrue();

    $lock->release();
});

it('reopens circuit when probe request fails', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    $exception = new Exception('Probe failed');
    $next = function () use ($exception) {
        throw $exception;
    };

    try {
        $this->middleware->handle($this->job, $next);
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('Probe failed');
    }

    expect($this->breaker->isOpen())->toBeTrue();
});

it('executes job when circuit breaker is disabled', function () {
    Cache::put('fuse:enabled', false);

    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    $executed = false;
    $next = function () use (&$executed) {
        $executed = true;
        return 'success';
    };

    $result = $this->middleware->handle($this->job, $next);

    expect($executed)->toBeTrue();
    expect($result)->toBe('success');

    Cache::forget('fuse:enabled');
});
