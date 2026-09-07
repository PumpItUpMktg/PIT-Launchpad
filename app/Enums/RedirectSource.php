<?php

namespace App\Enums;

enum RedirectSource: string
{
    case Migration = 'migration';
    case SlugChange = 'slug_change';
    case Duplicate = 'duplicate';
}
