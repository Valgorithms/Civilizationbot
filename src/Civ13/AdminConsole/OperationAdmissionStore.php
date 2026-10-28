<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Admission index only; never a second game lifecycle journal. No production implementation. */
interface OperationAdmissionStore
{
    /**
     * Atomically reserve key -> (fingerprint, operationId), or compare existing binding.
     * Return exactly reserved|existing|conflict|uncertain. existing MUST mean both
     * fingerprint AND operationId matched. Never overwrite/release a conflicting key.
     * A transport/commit exception is uncertain, not proof no reservation exists.
     * Real provider must durably commit before reserved, with a unique-key transaction.
     */
    public function reserve(string $key, string $fingerprint, string $operationId): string;
}
