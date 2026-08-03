<?php

namespace App\Enums;

enum KeywordSource: string
{
    case ServiceProblem = 'service_problem';
    case Seed = 'seed';
    case Generated = 'generated';
    case Gap = 'gap';
    /** A city keyword ("{service} {city}") pinned to a priority-city location page — geo, never a silo. */
    case Local = 'local';
}
