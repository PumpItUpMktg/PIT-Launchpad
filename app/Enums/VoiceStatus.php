<?php

namespace App\Enums;

enum VoiceStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
