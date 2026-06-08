<?php

namespace App\Enums;

/**
 * Why a published page was (or should be) refreshed. RefreshEvents are emitted
 * by §5 (position drop) and §6b/c (merge / news development / manual); §6a only
 * creates the table.
 */
enum RefreshTrigger: string
{
    case PositionDrop = 'position_drop';
    case NearDuplicateMerge = 'near_dup_merge';
    case NewsDevelopment = 'news_development';
    case Manual = 'manual';
}
