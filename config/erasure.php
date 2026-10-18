<?php

return [
    // These connections, keys and storage generation are outside application restores.
    // No application-database fallback is permitted.
    'evidence_key' => env('ERASURE_EVIDENCE_ENCRYPTION_KEY'),
    'privacy_operator' => env('ERASURE_PRIVACY_OPERATOR_REFERENCE'),
    'operator_environment' => env('ERASURE_OPERATOR_ENVIRONMENT'),
    'restore_target' => env('ERASURE_RESTORE_TARGET'),
    'restore_set' => env('ERASURE_RESTORE_SET'),
    'restore_manifest_digest' => env('ERASURE_RESTORE_MANIFEST_SHA256'),
    'release_certificate' => env('ERASURE_RELEASE_CERTIFICATE'),
    'release_public_key' => env('ERASURE_RELEASE_PUBLIC_KEY'),
    'release_signing_key' => env('ERASURE_RELEASE_SIGNING_KEY'),
    'installation' => env('ERASURE_INSTALLATION'),
    'journal_connections' => ['erasure_primary', 'erasure_mirror'],
    'journal_generation' => env('ERASURE_JOURNAL_GENERATION'),
    'encryption_key' => env('ERASURE_JOURNAL_ENCRYPTION_KEY'),
    'integrity_key' => env('ERASURE_JOURNAL_INTEGRITY_KEY'),
];
