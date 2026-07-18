<?php

namespace App\Enums;

use App\Publishing\OrphanScanner;

/**
 * The kinds of page-integrity problem the {@see OrphanScanner} reports. Each is a
 * distinct fix: re-parent (or take down) the child, take the stranded page off WordPress, or add a 301.
 */
enum OrphanType: string
{
    /** A live page whose parent (hub) was deleted — its nested URL / WP post_parent chain is broken. */
    case OrphanedChild = 'orphaned_child';

    /** A page deleted in the control plane but still carrying a wp_post_id — likely still live on WordPress. */
    case StrandedLive = 'stranded_live';

    /** A published page's URL that was retired (deleted, not recreated) with no redirect — it now 404s. */
    case MissingRedirect = 'missing_redirect';

    public function label(): string
    {
        return match ($this) {
            self::OrphanedChild => 'Orphaned child page',
            self::StrandedLive => 'Stranded live page',
            self::MissingRedirect => 'URL needs a 301',
        };
    }

    /** The recommended remediation, shown alongside the finding. */
    public function fix(): string
    {
        return match ($this) {
            self::OrphanedChild => 'Restore or recreate its parent hub, or re-home the page under a live hub.',
            self::StrandedLive => 'Take it down from WordPress (or republish it) — it should not be left live.',
            self::MissingRedirect => 'Add a 301 from the old URL to its replacement (or the parent/home).',
        };
    }
}
