<x-app-layout>
    <div class="max-w-xl mx-auto py-8">
        <h1 class="text-xl font-semibold">Save your recovery codes</h1>
        <p>Keep these separately from your authenticator. Each code can be used once. They will not be shown again.</p>
        <ul class="font-mono select-all">@foreach ($codes as $code)<li>{{ $code }}</li>@endforeach</ul>
        <button type="button" onclick="window.print()">Print recovery codes</button>
        <form method="POST" action="{{ route('security.second-factor.acknowledge') }}">@csrf
            <label><input type="checkbox" name="acknowledged" value="1" required /> I saved these recovery codes.</label>
            <x-primary-button class="mt-4">Finish setup</x-primary-button>
        </form>
    </div>
</x-app-layout>
