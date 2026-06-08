<?php

namespace App\Enums;

enum ProofType: string
{
    case Warranty = 'warranty';
    case Guarantee = 'guarantee';
    case License = 'license';
    case Cert = 'cert';
    case Award = 'award';
    case ReviewAggregate = 'review_aggregate';
    case Testimonial = 'testimonial';
    case Experience = 'experience';
    case Process = 'process';
    case Affiliation = 'affiliation';
    case Usp = 'usp';
}
