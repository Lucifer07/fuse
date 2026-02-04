# Fuse - Circuit Breaker for Laravel Queue Jobs

Circuit breaker pattern implementation for Laravel queue jobs. PHP 8.1+ compatible.

## Features

- **Three-State Circuit Breaker** — CLOSED (normal), OPEN (protected), HALF-OPEN (testing recovery)
- **Intelligent Failure Classification** — Rate limits (429) and auth errors (401, 403) don't trip the circuit
- **Peak Hours Support** — Different thresholds for business hours vs. off-peak
- **Fixed Window Tracking** — Minute-based buckets with automatic expiration
- **Thundering Herd Prevention** — `Cache::lock()` ensures only one worker probes during recovery
- **Zero Data Loss** — Jobs are delayed with `release()`, not failed permanently
- **Automatic Recovery** — Circuit tests and heals itself when services return
- **Per-Service Circuits** — Separate breakers for each service
- **Laravel Events** — Get notified on state transitions for alerting and monitoring

## Requirements

- PHP 8.1+
- Laravel 10+
- Redis (recommended) or any Laravel cache driver

## Installation

```bash
composer require lucifer07/fuse
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=fuse-config
```

## Quick Start

Add the middleware to your job:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Fuse\Middleware\CircuitBreakerMiddleware;

class ChargeCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 0;           // Unlimited releases
    public $maxExceptions = 3;   // Only real failures count

    public function middleware(): array
    {
        return [new CircuitBreakerMiddleware('stripe')];
    }

    public function handle(): void
    {
        // Your payment logic - unchanged
        Stripe::charges()->create([...]);
    }
}
```

## How It Works

### CLOSED (Normal Operations)
All requests pass through. Failures are tracked in the background.

### OPEN (Protection Mode)
After failure threshold is exceeded, circuit trips. Jobs fail instantly (1ms, not 30s) and are delayed for automatic retry. No API calls are made.

### HALF-OPEN (Testing Recovery)
After timeout period, one probe request tests if service recovered. Success closes circuit. Failure reopens it.

## Configuration

Edit `config/fuse.php`:

```php
return [
    'enabled' => env('FUSE_ENABLED', true),

    'default_threshold' => 50,      // Failure rate percentage to trip circuit
    'default_timeout' => 60,        // Seconds before testing recovery
    'default_min_requests' => 10,   // Minimum requests before evaluating

    'services' => [
        'stripe' => [
            'threshold' => 40,              // Off-peak threshold
            'peak_hours_threshold' => 60,    // Peak hours threshold
            'peak_hours_start' => 9,         // 9 AM
            'peak_hours_end' => 17,          // 5 PM
            'timeout' => 30,
            'min_requests' => 5,
        ],
        'mailgun' => [
            'threshold' => 50,
            'peak_hours_threshold' => 70,
            'peak_hours_start' => 9,
            'peak_hours_end' => 17,
            'timeout' => 120,
            'min_requests' => 10,
        ],
    ],
];
```

## Peak Hours

Configure different thresholds for business hours when every transaction matters:

```php
'stripe' => [
    'threshold' => 40,              // Off-peak: more sensitive (40%)
    'peak_hours_threshold' => 60,    // Peak hours: more tolerant (60%)
    'peak_hours_start' => 9,        // 9 AM
    'peak_hours_end' => 17,         // 5 PM
    'timeout' => 30,
    'min_requests' => 5,
],
```

During peak hours (9 AM - 5 PM), the circuit uses the higher threshold to maximize successful transactions. Outside peak hours, it uses the lower threshold for earlier protection.

This is especially useful for payment processing, email sending, and other critical services where:
- **Peak hours** (e.g., 9-17): Higher threshold to avoid blocking transactions when volume is high
- **Off-peak** (e.g., 17-9): Lower threshold for faster detection of issues during low-traffic periods

## Intelligent Failure Classification

Not all errors indicate service is down. Fuse only counts real outages:

| Error Type | Counted as Failure? | Reason |
|------------|-------------------|---------|
| 500, 502, 503 | Yes | Server errors indicate service problems |
| Connection timeout | Yes | Service is unreachable |
| Connection refused | Yes | Service is unreachable |
| 429 Too Many Requests | No | Service is healthy, just rate limiting |
| 401 Unauthorized | No | Your API key is wrong, not a service issue |
| 403 Forbidden | No | Permission issue, not a service outage |
| 400 Bad Request | Yes | Could indicate API issues |
| 404 Not Found | Yes | Could indicate API changes |

## Events

Fuse dispatches Laravel events on state transitions:

```php
use Fuse\Events\CircuitBreakerOpened;
use Fuse\Events\CircuitBreakerHalfOpen;
use Fuse\Events\CircuitBreakerClosed;

// In EventServiceProvider
protected $listen = [
    CircuitBreakerOpened::class => [
        AlertOnCircuitOpen::class,
    ],
    CircuitBreakerClosed::class => [
        LogCircuitRecovery::class,
    ],
];
```

### Event Properties

**CircuitBreakerOpened:**
- `$service` — The service name (e.g., "stripe")
- `$failureRate` — Current failure percentage
- `$attempts` — Total requests in the window
- `$failures` — Failed requests in the window

**CircuitBreakerHalfOpen:**
- `$service` — The service name

**CircuitBreakerClosed:**
- `$service` — The service name

## Direct Usage

Use the circuit breaker directly outside jobs:

```php
use Fuse\CircuitBreaker;

$breaker = new CircuitBreaker('stripe');

if (!$breaker->isOpen()) {
    try {
        $result = Stripe::charges()->create([...]);
        $breaker->recordSuccess();
        return $result;
    } catch (Exception $e) {
        $breaker->recordFailure($e);
        throw $e;
    }
} else {
    // Circuit is open - use fallback
    return $this->fallbackResponse();
}
```

### Check Circuit State

```php
$breaker = new CircuitBreaker('stripe');

$breaker->isClosed();    // Normal operations
$breaker->isOpen();      // Protected, failing fast
$breaker->isHalfOpen();  // Testing recovery

$breaker->getStats();    // Get full statistics
$breaker->reset();       // Manually reset to closed
```

### Stats Output

```php
$stats = $breaker->getStats();

[
    'state' => 'open',
    'attempts' => 15,
    'failures' => 12,
    'failure_rate' => 80.0,
    'opened_at' => 1706922000,
    'recovery_at' => 1706922060,
    'timeout' => 60,
    'threshold' => 50,
    'min_requests' => 10,
]
```

## Dynamic Configuration

Enable/disable circuit breaker at runtime:

```php
use Illuminate\Support\Facades\Cache;

// Disable circuit breaker
Cache::put('fuse:enabled', false);

// Enable circuit breaker
Cache::put('fuse:enabled', true);

// Let config decide
Cache::forget('fuse:enabled');
```

## License

MIT
