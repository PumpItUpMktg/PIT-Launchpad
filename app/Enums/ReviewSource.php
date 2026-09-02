<?php

namespace App\Enums;

/**
 * Where a captured review came from (Review Capture §5). `first_party` = solicited from a real completed job
 * via the module's own request flow; `imported` = brought in from an external platform (Google, Facebook,
 * Angi, …) — the specific platform is recorded free-form in `import_source`.
 */
enum ReviewSource: string
{
    case FirstParty = 'first_party';
    case Imported = 'imported';

    public function label(): string
    {
        return $this === self::FirstParty ? 'First-party' : 'Imported';
    }
}
