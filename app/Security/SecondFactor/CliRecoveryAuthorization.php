<?php

namespace App\Security\SecondFactor;

use SensitiveParameter;

final readonly class CliRecoveryAuthorization
{
    private string $value;

    public function __construct(
        public string $id,
        #[SensitiveParameter] string $value,
    ) {
        $this->value = $value;
    }

    public function plaintextForInitialDisplay(): string
    {
        return $this->value;
    }
}
