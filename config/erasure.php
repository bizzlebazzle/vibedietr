<?php

return [
    // These connections, keys and storage generation are outside application restores.
    // No application-database fallback is permitted.
    'installation' => env('ERASURE_INSTALLATION'),
    'journal_connections' => ['erasure_primary', 'erasure_mirror'],
    'journal_generation' => env('ERASURE_JOURNAL_GENERATION'),
    'encryption_key' => env('ERASURE_JOURNAL_ENCRYPTION_KEY'),
    'integrity_key' => env('ERASURE_JOURNAL_INTEGRITY_KEY'),
];
