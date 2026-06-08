<?php

namespace App\Enums;

enum MediaSource: string
{
    case Uploaded = 'uploaded';
    case Generated = 'generated';
}
