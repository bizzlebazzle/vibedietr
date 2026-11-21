<x-guest-layout>
    <x-slot name="title">Privacy and service information</x-slot>
    <div class="space-y-5">
        <h1 class="text-2xl font-semibold">Privacy and service information</h1>
        <p>Launch review is pending. This information describes current application behavior; the installation-specific privacy notice and terms still require owner review before launch.</p>

        <section class="space-y-2" aria-labelledby="information-privacy">
            <h2 id="information-privacy" class="text-lg font-semibold">Your information</h2>
            <p>Your account holds your name, email, recipes, meal plans, diary and nutrition targets. Private content is restricted to its owner unless an implemented sharing action grants access. Publishing content makes it available to others. Independently owned copies follow their own lifecycle.</p>
            <p>Account exports are available from Profile. They include owned history and snapshots, exclude other accounts' private data, and use archive-local aliases for outgoing-share recipients. An export does not replace a complete rights-request process.</p>
            <p>The controller identity, dedicated contact email, processor inventory and full rights-request procedure have not yet been published. There is currently no dedicated private webapp contact or reconsideration workflow.</p>
        </section>

        <section class="space-y-2" aria-labelledby="information-deletion">
            <h2 id="information-deletion" class="text-lg font-semibold">Account deletion</h2>
            <x-account-deletion-notice />
            <p>The approved backup policy calls for recovery eligibility to end seven elapsed UTC days after capture, removal within a further 24 hours, and protected erasure-reference disposal normally within nine days of permanent erasure. These are policy targets; installation-specific disposal and restore verification remain outstanding. They are not a guarantee that every recoverable copy has been destroyed.</p>
        </section>

        <section class="space-y-2" aria-labelledby="information-cookies">
            <h2 id="information-cookies" class="text-lg font-semibold">Cookies and browser storage</h2>
            <p>The application uses a session cookie for session state and an XSRF-TOKEN cookie for request protection. Choosing Remember me adds a persistent sign-in cookie. Your theme preference is stored in local storage in this browser.</p>
            <p>The application does not include advertising or analytics tracking. Production proxy, hosting and provider behavior still needs verification.</p>
        </section>

        <section class="space-y-2" aria-labelledby="information-nutrition">
            <h2 id="information-nutrition" class="text-lg font-semibold">Nutrition and safety</h2>
            <p>Calculated nutrition is an estimate. Imported and manually entered values may be incomplete or inaccurate. Check source information and food labels, including allergens; do not rely on these values for medical decisions. User-entered nutrition targets are not medical advice.</p>
            <p>Children's age-band safeguards and the reporting and safety-response workflows required for launch are not yet implemented. Launch review remains blocked pending these safeguards and operational verification.</p>
        </section>
    </div>
</x-guest-layout>
