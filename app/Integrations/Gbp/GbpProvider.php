<?php

namespace App\Integrations\Gbp;

/**
 * Capability role: Google Business Profile. Seeds the Service Catalog checklist
 * from a primary category's service types and validates a connect credential.
 * Real GBP calls are deferred — implementations map raw GBP output here.
 */
interface GbpProvider
{
    /**
     * Candidate service-type names for a primary business category.
     *
     * @return list<string>
     */
    public function serviceTypes(string $primaryCategory): array;
}
