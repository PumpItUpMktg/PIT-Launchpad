<?php

namespace App\Enums;

enum SocialSuggestionStatus: string
{
    case Open = 'open';
    case Approved = 'approved';
    case Skipped = 'skipped';
    case Auto = 'auto';   // a rule sent it without asking (rain) — recorded here so the board shows why
}
