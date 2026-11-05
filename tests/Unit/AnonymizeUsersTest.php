<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

it('anonymizes the name column of the default Laravel users table', function () {
    $this->loadLaravelMigrations();
    config(['auth.providers.users.model' => User::class]);
    DB::table('users')->insert(['name' => 'Jane Doe', 'email' => 'jane@client.nl', 'password' => 'secret']);

    (new AnonymizeUsers('users'))->process();

    $user = DB::table('users')->first();
    expect($user->name)->toStartWith('User_')
        ->and($user->email)->toBe(strtolower($user->name) . '@example.com');
});

it('anonymizes first_name and last_name when the users table has them', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email')->unique();
    });
    config(['auth.providers.users.model' => User::class]);
    DB::table('users')->insert(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@client.nl']);

    (new AnonymizeUsers('users'))->process();

    $user = DB::table('users')->first();
    expect($user->first_name)->toStartWith('User_')
        ->and($user->last_name)->toBe($user->first_name)
        ->and($user->email)->toBe(strtolower($user->first_name) . '@example.com');
});
