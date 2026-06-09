<?php

namespace App\Console\VendorProbes;

use Illuminate\Contracts\Container\Container;

/**
 * Discovers every VendorProbe under VendorProbes/Probes/ and resolves it through
 * the container (so probes can type-hint their dependencies), ordered by each
 * probe's order(). Adding a vendor probe class is all it takes to register it —
 * no edit here, no edit to the command.
 */
class VendorProbeRegistry
{
    public function __construct(private readonly Container $app) {}

    /**
     * @return list<VendorProbe>
     */
    public function all(): array
    {
        $probes = [];

        foreach (glob(__DIR__.'/Probes/*.php') ?: [] as $file) {
            $class = __NAMESPACE__.'\\Probes\\'.basename($file, '.php');

            if (is_subclass_of($class, VendorProbe::class)) {
                /** @var VendorProbe $probe */
                $probe = $this->app->make($class);
                $probes[] = $probe;
            }
        }

        usort($probes, fn (VendorProbe $a, VendorProbe $b) => $a->order() <=> $b->order());

        return $probes;
    }
}
