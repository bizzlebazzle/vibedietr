<?php

namespace App\Observability\Monitoring;

use App\Observability\OperationalTelemetry;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

final class QueueTelemetryListener
{
    /** @var array<string, float> */
    private array $started = [];

    public function __construct(
        private readonly OperationalState $state,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    public function processing(JobProcessing $event): void
    {
        $queue = $event->job->getQueue();
        $id = $event->job->uuid() ?? spl_object_hash($event->job);
        $this->started[$id] = microtime(true);
        $this->state->recordWorker($queue);
        $payload = $event->job->payload();
        if (isset($payload['pushedAt']) && is_numeric($payload['pushedAt'])) {
            $this->telemetry->timing('queue.dispatch_to_start', (microtime(true) - (float) $payload['pushedAt']) * 1000, [
                'queue' => $queue,
                'job_type' => $event->job->resolveName(),
            ]);
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->complete($event->job, 'success');
    }

    public function exception(JobExceptionOccurred $event): void
    {
        $this->state->recordWorker($event->job->getQueue());
        $this->telemetry->counter('queue.retry', [
            'queue' => $event->job->getQueue(),
            'job_type' => $event->job->resolveName(),
            'outcome' => 'retry',
        ]);
    }

    public function failed(JobFailed $event): void
    {
        $this->complete($event->job, 'failed');
        $this->telemetry->counter('queue.final_failure', [
            'queue' => $event->job->getQueue(),
            'job_type' => $event->job->resolveName(),
            'outcome' => 'failed',
        ]);
    }

    private function complete(object $job, string $outcome): void
    {
        $id = $job->uuid() ?? spl_object_hash($job);
        $started = $this->started[$id] ?? microtime(true);
        unset($this->started[$id]);
        $this->state->recordWorker($job->getQueue());
        $this->telemetry->timing('queue.execution', (microtime(true) - $started) * 1000, [
            'queue' => $job->getQueue(),
            'job_type' => $job->resolveName(),
            'outcome' => $outcome,
        ]);
    }
}
