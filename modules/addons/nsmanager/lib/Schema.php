<?php

namespace NsManager;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Creates the addon tables. Idempotent: safe to call on activate and upgrade.
 */
class Schema
{
    public const REQUESTS = 'mod_nsmanager_requests';
    public const DOMAINS = 'mod_nsmanager_domains';
    public const LOG = 'mod_nsmanager_log';

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::REQUESTS)) {
            $schema->create(self::REQUESTS, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('domain_id')->index();
                $table->string('domain', 255)->index();
                $table->unsignedInteger('client_id')->index();
                $table->text('old_ns')->nullable();
                $table->text('new_ns');
                $table->string('status', 20)->default('pending')->index();
                $table->string('requested_by', 64);
                $table->string('requester_ip', 45)->nullable();
                $table->text('admin_note')->nullable();
                $table->string('resolved_by', 64)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('last_reminded_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Added in 0.2.0 (DNS auto-verify throttle); installs from 0.1.0 get it here on upgrade.
        if (!$schema->hasColumn(self::REQUESTS, 'last_checked_at')) {
            $schema->table(self::REQUESTS, function ($table) {
                $table->timestamp('last_checked_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::DOMAINS)) {
            $schema->create(self::DOMAINS, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('domain_id')->unique();
                $table->string('provider', 100)->nullable();
                $table->string('provider_login_url', 255)->nullable();
                $table->string('account_label', 100)->nullable();
                $table->text('notes')->nullable();
                $table->text('applied_ns')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::LOG)) {
            $schema->create(self::LOG, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('domain_id')->nullable()->index();
                $table->unsignedInteger('request_id')->nullable()->index();
                $table->string('event', 50);
                $table->string('actor', 64);
                $table->text('details')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
