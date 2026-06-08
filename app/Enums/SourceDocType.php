<?php

namespace App\Enums;

enum SourceDocType: string
{
    case Spec = 'spec';
    case WarrantyPdf = 'warranty_pdf';
    case Manual = 'manual';
    case LikedCopy = 'liked_copy';
}
