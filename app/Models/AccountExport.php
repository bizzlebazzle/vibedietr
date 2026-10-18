<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $deleted_at
 */
class AccountExport extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'generated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    public function storageKey(): string
    {
        return 'account-exports/'.$this->getKey().'.zip';
    }

    public function stagingPath(): string
    {
        return storage_path('app/private/account-export-staging/'.$this->getKey().'.zip');
    }
}
