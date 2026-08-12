<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityNotificationHealth extends Model
{
    protected $table = 'security_notification_health';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['channel_healthy' => 'boolean', 'capacity_available' => 'boolean', 'clock_synchronized' => 'boolean', 'audit_persistence_healthy' => 'boolean', 'provider_accepted_at' => 'immutable_datetime', 'capacity_checked_at' => 'immutable_datetime', 'worker_heartbeat_at' => 'immutable_datetime', 'failure_monitor_heartbeat_at' => 'immutable_datetime'];
    }
}
