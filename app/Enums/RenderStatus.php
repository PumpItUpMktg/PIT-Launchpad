<?php

namespace App\Enums;

enum RenderStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case RenderFailed = 'render_failed';
}
