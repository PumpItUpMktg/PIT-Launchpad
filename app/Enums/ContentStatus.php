<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Candidate = 'candidate';
    case Scored = 'scored';
    case Drafted = 'drafted';
    case NeedsReview = 'needs_review';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rendering = 'rendering';
    case Publishing = 'publishing';
    case Published = 'published';
    case RenderFailed = 'render_failed';
    case PublishFailed = 'publish_failed';
    case Rejected = 'rejected';
}
