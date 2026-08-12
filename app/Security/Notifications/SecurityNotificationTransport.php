<?php

namespace App\Security\Notifications;

use App\Models\SecurityNotificationIntent;
use App\Models\User;

interface SecurityNotificationTransport
{
    public function send(User $recipient, SecurityNotificationIntent $intent): string;
}
