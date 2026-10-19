<?php

namespace App\Enums;

/**
 * What kind of site a link prospect is (§ Links) — decides the ask: a directory takes a listing, a local
 * site takes a partnership or sponsorship, an editorial site takes a story or a cited resource.
 */
enum LinkProspectKind: string
{
    case Directory = 'directory';
    case Local = 'local';
    case Editorial = 'editorial';
    case National = 'national';

    public function label(): string
    {
        return match ($this) {
            self::Directory => 'Directory',
            self::Local => 'Local site',
            self::Editorial => 'Editorial / gov',
            self::National => 'National',
        };
    }
}
