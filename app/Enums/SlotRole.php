<?php

namespace App\Enums;

/**
 * A slot's rhetorical position in the problem/solution arc. Tells generation
 * (later) each slot's framing; the default arc comes from VoiceProfile
 * framing_model. Here it also identifies which slots earn a page (role: proof).
 */
enum SlotRole: string
{
    case HeroProblem = 'hero_problem';
    case HeroSolution = 'hero_solution';
    case BodyExplainer = 'body_explainer';
    case Proof = 'proof';
    case Faq = 'faq';
    case Cta = 'cta';
    case Contact = 'contact';
    case Navigation = 'navigation';
    case LocalRelevance = 'local_relevance';
}
