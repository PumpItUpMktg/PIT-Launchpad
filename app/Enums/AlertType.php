<?php

namespace App\Enums;

/**
 * Operator-alert categories raised by the candidate funnel. Nothing changes a
 * live page silently — every dedup/refresh/merge outcome alerts the operator.
 */
enum AlertType: string
{
    case RefreshSuggested = 'refresh_suggested';
    case NearDuplicateFlag = 'near_duplicate_flag';
    case BrandSafetyRejected = 'brand_safety_rejected';
    case BorderlineRelevance = 'borderline_relevance';
}
