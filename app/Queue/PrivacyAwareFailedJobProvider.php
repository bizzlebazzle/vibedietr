<?php

namespace App\Queue;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

final class PrivacyAwareFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(
        private readonly FailedJobProviderInterface $provider,
        private readonly FailedJobPruner $pruner,
    ) {}

    public function log($connection, $queue, $payload, $exception)
    {
        $id = $this->provider->log($connection, $queue, $payload, $exception);

        if ($id !== null && $this->pruner->classification((string) $payload) === 'personal') {
            $this->provider->forget($id);
        }

        return $id;
    }

    public function ids($queue = null)
    {
        return $this->provider->ids($queue);
    }

    public function all()
    {
        return $this->provider->all();
    }

    public function find($id)
    {
        return $this->provider->find($id);
    }

    public function forget($id)
    {
        return $this->provider->forget($id);
    }

    public function flush($hours = null)
    {
        $this->provider->flush($hours);
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->provider instanceof PrunableFailedJobProvider
            ? $this->provider->prune($before)
            : 0;
    }

    public function count($connection = null, $queue = null)
    {
        return $this->provider instanceof CountableFailedJobProvider
            ? $this->provider->count($connection, $queue)
            : count($this->provider->ids($queue));
    }
}
