<?php


use Fuse\CircuitBreaker;
use Fuse\Events\CircuitBreakerClosed;
use Fuse\Events\CircuitBreakerHalfOpen;
use Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use GuzzleHttp\Exception\ClientException;

beforeEach(function () {
    Event::fake();
    $this->breaker = new CircuitBreaker('test-service');
    $this->breaker->reset();
});

it('initializes in closed state', function () {
    expect($this->breaker->isClosed())->toBeTrue();
    expect($this->breaker->isOpen())->toBeFalse();
    expect($this->breaker->isHalfOpen())->toBeFalse();
});

it('remains closed below failure threshold', function () {
    config()->set('fuse.services.test-service.min_requests', 5);
    config()->set('fuse.services.test-service.threshold', 50);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isClosed())->toBeTrue();
});

it('opens circuit when failure threshold exceeded', function () {
    config()->set('fuse.services.test-service.min_requests', 5);
    config()->set('fuse.services.test-service.threshold', 50);

    for ($i = 0; $i < 5; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();
    Event::assertDispatched(CircuitBreakerOpened::class);
});

it('transitions to half-open after timeout', function () {
    config()->set('fuse.services.test-service.min_requests', 5);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 5; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    expect($this->breaker->isOpen())->toBeFalse();
    expect($this->breaker->isHalfOpen())->toBeTrue();
    Event::assertDispatched(CircuitBreakerHalfOpen::class);
});

it('closes circuit on success during half-open', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    $this->breaker->recordSuccess();

    expect($this->breaker->isClosed())->toBeTrue();
    Event::assertDispatched(CircuitBreakerClosed::class);
});

it('reopens circuit on failure during half-open', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 1);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    sleep(2);

    $this->breaker->recordFailure();

    expect($this->breaker->isOpen())->toBeTrue();
});

it('does not count 429 as failure', function () {
    $exception = new TooManyRequestsHttpException();

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(0);
});

it('does not count 401 as failure', function () {
    $mockResponse = new \GuzzleHttp\Psr7\Response(401);
    $exception = new ClientException('Unauthorized', new \GuzzleHttp\Psr7\Request('GET', '/'), $mockResponse);

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(0);
});

it('does not count 403 as failure', function () {
    $mockResponse = new \GuzzleHttp\Psr7\Response(403);
    $exception = new ClientException('Forbidden', new \GuzzleHttp\Psr7\Request('GET', '/'), $mockResponse);

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(0);
});

it('counts 500 as failure', function () {
    $mockResponse = new \GuzzleHttp\Psr7\Response(500);
    $exception = new ClientException('Internal Server Error', new \GuzzleHttp\Psr7\Request('GET', '/'), $mockResponse);

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(1);
});

it('counts 502 as failure', function () {
    $mockResponse = new \GuzzleHttp\Psr7\Response(502);
    $exception = new ClientException('Bad Gateway', new \GuzzleHttp\Psr7\Request('GET', '/'), $mockResponse);

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(1);
});

it('counts 503 as failure', function () {
    $mockResponse = new \GuzzleHttp\Psr7\Response(503);
    $exception = new ClientException('Service Unavailable', new \GuzzleHttp\Psr7\Request('GET', '/'), $mockResponse);

    $this->breaker->recordFailure($exception);

    $stats = $this->breaker->getStats();

    expect($stats['failures'])->toBe(1);
});

it('returns correct stats', function () {
    config()->set('fuse.services.test-service.min_requests', 5);
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.timeout', 60);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure();
    }

    $stats = $this->breaker->getStats();

    expect($stats['state'])->toBe('closed');
    expect($stats['attempts'])->toBe(3);
    expect($stats['failures'])->toBe(3);
    expect($stats['failure_rate'])->toBe(100.0);
    expect($stats['timeout'])->toBe(60);
    expect($stats['threshold'])->toBe(50);
    expect($stats['min_requests'])->toBe(5);
});

it('resets circuit to closed state', function () {
    config()->set('fuse.services.test-service.min_requests', 2);
    config()->set('fuse.services.test-service.threshold', 50);

    for ($i = 0; $i < 2; $i++) {
        $this->breaker->recordFailure();
    }

    expect($this->breaker->isOpen())->toBeTrue();

    $this->breaker->reset();

    expect($this->breaker->isClosed())->toBeTrue();
});

it('uses default threshold when service not configured', function () {
    $breaker = new CircuitBreaker('non-existent-service');

    $stats = $breaker->getStats();

    expect($stats['threshold'])->toBe(50);
    expect($stats['timeout'])->toBe(60);
    expect($stats['min_requests'])->toBe(10);
});

it('increments attempts on success', function () {
    $this->breaker->recordSuccess();
    $this->breaker->recordSuccess();

    $stats = $this->breaker->getStats();

    expect($stats['attempts'])->toBe(2);
    expect($stats['failures'])->toBe(0);
});
