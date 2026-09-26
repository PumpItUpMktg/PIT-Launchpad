<?php

namespace App\JobCapture\Photos;

use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The public CDN URL for a stored job photo key — '' when the disk has no public URL (e.g. tests). */
final class PhotoUrl
{
    public static function for(string $key): string
    {
        if ($key === '') {
            return '';
        }
        try {
            return Storage::disk(TenantStorage::DISK)->url($key);
        } catch (Throwable) {
            return '';
        }
    }
}
