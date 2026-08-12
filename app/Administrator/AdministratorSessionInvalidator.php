<?php

namespace App\Administrator;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdministratorSessionInvalidator
{
    public function invalidate(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
