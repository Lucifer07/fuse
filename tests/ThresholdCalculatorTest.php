<?php

use Fuse\ThresholdCalculator;
use Illuminate\Support\Carbon;


beforeEach(function () {
    Carbon::setTestNow(now());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns default threshold when service not configured', function () {
    $threshold = ThresholdCalculator::for('non-existent-service');

    expect($threshold)->toBe(50);
});

it('returns off-peak threshold outside peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(8));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(40);
});

it('returns peak threshold during peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(10));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(60);
});

it('returns peak threshold at peak start time', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(9)->setMinute(0));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(60);
});

it('returns peak threshold at peak end time', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(17)->setMinute(0));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(60);
});

it('returns off-peak threshold after peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(18));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(40);
});

it('returns off-peak threshold before peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(0));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(40);
});

it('uses regular threshold when peak hours threshold not set', function () {
    config()->set('fuse.services.test-service.threshold', 50);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(10));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(50);
});

it('uses default threshold when only peak hours threshold set', function () {
    config()->set('fuse.services.test-service.peak_hours_threshold', 70);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(10));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(70);
});

it('returns correct config during peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);
    config()->set('fuse.services.test-service.timeout', 30);
    config()->set('fuse.services.test-service.min_requests', 5);

    Carbon::setTestNow(now()->setHour(10));

    $config = ThresholdCalculator::getConfig('test-service');

    expect($config['threshold'])->toBe(60);
    expect($config['timeout'])->toBe(30);
    expect($config['min_requests'])->toBe(5);
    expect($config['is_peak_hours'])->toBeTrue();
});

it('returns correct config during off-peak hours', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);
    config()->set('fuse.services.test-service.timeout', 30);
    config()->set('fuse.services.test-service.min_requests', 5);

    Carbon::setTestNow(now()->setHour(8));

    $config = ThresholdCalculator::getConfig('test-service');

    expect($config['threshold'])->toBe(40);
    expect($config['timeout'])->toBe(30);
    expect($config['min_requests'])->toBe(5);
    expect($config['is_peak_hours'])->toBeFalse();
});

it('uses default values in config when service not configured', function () {
    $config = ThresholdCalculator::getConfig('non-existent-service');

    expect($config['threshold'])->toBe(50);
    expect($config['timeout'])->toBe(60);
    expect($config['min_requests'])->toBe(10);
    expect($config['is_peak_hours'])->toBeFalse();
});

it('handles midnight correctly', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(0)->setMinute(0)->setSecond(0));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(40);
});

it('handles 23:59 correctly', function () {
    config()->set('fuse.services.test-service.threshold', 40);
    config()->set('fuse.services.test-service.peak_hours_threshold', 60);
    config()->set('fuse.services.test-service.peak_hours_start', 9);
    config()->set('fuse.services.test-service.peak_hours_end', 17);

    Carbon::setTestNow(now()->setHour(23)->setMinute(59)->setSecond(59));

    $threshold = ThresholdCalculator::for('test-service');

    expect($threshold)->toBe(40);
});
