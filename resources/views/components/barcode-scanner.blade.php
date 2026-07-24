@props([
    // 'environment' (rear) or 'user' (front)
    'facing' => 'environment',
    // autostart scanning on mount
    'autostart' => false,
    // browser event name to dispatch when code is detected
    'event' => 'barcode-scanned',
    // ZXing CDN
    'cdn' => 'https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js',
])

<div
  x-data="barcodeScanner({
      cdn: @js($cdn),
      facing: @js($facing),
      autostart: @js($autostart),
      eventName: @js($event),
  })"
  x-init="init()"
  x-on:visibilitychange.window="document.hidden ? stop() : maybeStart()"
  class="space-y-3"
  wire:ignore
>
  <div class="flex items-center gap-2">
    <button type="button" class="rounded border px-3 py-2" x-show="!running" x-on:click="start()">Start scan</button>
    <button type="button" class="rounded border px-3 py-2" x-show="running" x-on:click="stop()">Stop</button>

    <select class="ml-auto border rounded px-2 py-1 text-sm" x-model="selectedDeviceId" x-on:change="restartWithDevice()">
      <template x-for="d in devices" :key="d.deviceId">
        <option :value="d.deviceId" x-text="d.label || 'Camera'"></option>
      </template>
      <option x-show="devices.length === 0" disabled>No cameras found</option>
    </select>
  </div>

  <div class="mt-2 aspect-video bg-black rounded overflow-hidden">
    <video x-ref="video" class="w-full h-full object-cover" playsinline></video>
  </div>
</div>
