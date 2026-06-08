<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Candidate = 'candidate';
    case Scored = 'scored';
    case Drafted = 'drafted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Publishing = 'publishing';
    case Published = 'published';
    case RenderFailed = 'render_failed';
    case Rejected = 'rejected';
}
