<?php

use VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers;

it('applies to the users table', function () {
    $processor = new AnonymizeUsers('users');
    expect($processor->tables())->toBe(['users']);
    expect($processor->applies())->toBeTrue();
});

it('does not apply to other tables', function () {
    $processor = new AnonymizeUsers('orders');
    expect($processor->applies())->toBeFalse();
});

it('uses example.com as default email domain', function () {
    $processor = new AnonymizeUsers('users');
    $method = new ReflectionMethod($processor, 'getEmailDomain');
    expect($method->invoke($processor))->toBe('example.com');
});

it('uses configured email domain', function () {
    $processor = new AnonymizeUsers('users', ['email_domain' => 'van-ons.nl']);
    $method = new ReflectionMethod($processor, 'getEmailDomain');
    expect($method->invoke($processor))->toBe('van-ons.nl');
});

it('returns empty preserve emails list by default', function () {
    $processor = new AnonymizeUsers('users');
    $method = new ReflectionMethod($processor, 'getPreserveEmails');
    expect($method->invoke($processor))->toBe([]);
});

it('returns configured preserve emails', function () {
    $processor = new AnonymizeUsers('users', ['preserve_emails' => ['@van-ons.nl', 'admin@example.com']]);
    $method = new ReflectionMethod($processor, 'getPreserveEmails');
    expect($method->invoke($processor))->toBe(['@van-ons.nl', 'admin@example.com']);
});

it('returns null for password override when not configured', function () {
    $processor = new AnonymizeUsers('users');
    $method = new ReflectionMethod($processor, 'getPasswordOverride');
    expect($method->invoke($processor))->toBeNull();
});
