@props([
    // 'environment' (rear) or 'user' (front)
    'facing' => 'environment',
    // autostart scanning on mount
    'autostart' => false,
    // browser event name to dispatch when code is detected
    'event' => 'barcode-scanned',
])

<div
  x-data="barcodeScanner({
      facing: @js($facing),
      autostart: @js($autostart),
      eventName: @js($event),
  })"
  x-init="init()"
  x-on:visibilitychange.window="document.hidden ? stop() : maybeStart()"
  class="space-y-3"
  wire:ignore
>
  <p class="text-sm text-gray-600 dark:text-slate-300">
    Camera scanning is optional. You can always enter the product manually.
  </p>

  <div
    x-show="error"
    x-text="error"
    role="alert"
    class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
  ></div>

  <div class="flex items-center gap-2">
    <button type="button" class="rounded border px-3 py-2 disabled:opacity-50" x-show="!running" x-bind:disabled="starting" x-on:click="start()">
      <span x-show="!starting">Start scan</span>
      <span x-show="starting">Starting…</span>
    </button>
    <button type="button" class="rounded border px-3 py-2" x-show="running" x-on:click="stop()">Stop</button>

    <select class="ml-auto border rounded px-2 py-1 text-sm disabled:opacity-50" x-model="selectedDeviceId" x-bind:disabled="starting || devices.length === 0" x-on:change="restartWithDevice()">
      <template x-for="d in devices" :key="d.deviceId">
        <option :value="d.deviceId" x-text="d.label || 'Camera'"></option>
      </template>
      <option x-show="devices.length === 0" value="" disabled>No cameras available</option>
    </select>
  </div>

  <div class="mt-2 aspect-video bg-black rounded overflow-hidden">
    <video x-ref="video" class="w-full h-full object-cover" playsinline></video>
  </div>
</div>
