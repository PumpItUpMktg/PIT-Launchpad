<?php

namespace App\Enums;

enum KeywordSource: string
{
    case ServiceProblem = 'service_problem';
    case Seed = 'seed';
    case Generated = 'generated';
    case Gap = 'gap';
}
