<?php

namespace App\Enums;

enum PageType: string
{
    case Home = 'home';
    case Service = 'service';
    case Location = 'location';
    case Hub = 'hub';
    case Utility = 'utility';
    case Pillar = 'pillar';
    case Cluster = 'cluster';
}
