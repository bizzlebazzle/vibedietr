<?php

namespace App\Queue;

final class QueueName
{
    public const DEFAULT = 'default';

    public const SECURITY_NOTIFICATIONS = 'security-notifications';

    private function __construct() {}
}
