<?php

namespace App\SiloCreator;

use App\Models\Service;

/**
 * Seeds a silo's rule_set from the service scope + customer problems (service
 * silos) or theme terms (topical silos). §5 later refines with SERP signal.
 */
class RuleSetSeeder
{
    public function forService(Service $service): RuleSet
    {
        $phrases = [$service->name];

        if (is_string($service->scope) && $service->scope !== '') {
            $phrases[] = $service->scope;
        }

        foreach ($service->problems as $problem) {
            $phrases[] = $problem->phrase;
        }

        $seed = Terms::fromPhrases($phrases);

        return new RuleSet(
            seedTerms: $seed,
            includePatterns: $seed,
            excludePatterns: [],
        );
    }

    /**
     * @param  list<string>  $themeTerms
     * @param  iterable<string>  $problemPhrases
     */
    public function forTheme(string $name, array $themeTerms, iterable $problemPhrases): RuleSet
    {
        $seed = Terms::fromPhrases([$name, ...$themeTerms, ...$problemPhrases]);
        $include = Terms::fromPhrases([...$themeTerms, $name]);

        return new RuleSet(
            seedTerms: $seed,
            includePatterns: $include !== [] ? $include : $seed,
            excludePatterns: [],
        );
    }
}
