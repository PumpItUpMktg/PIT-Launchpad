<?php

namespace App\Enums;

/**
 * The security-relevant actions recorded in the append-only audit log: who
 * revealed or rotated a credential, what was published, who changed a role, and
 * when a site went live.
 */
enum AuditAction: string
{
    case CredentialRevealed = 'credential_revealed';
    case CredentialRotated = 'credential_rotated';
    case ContentPublished = 'content_published';
    case RoleChanged = 'role_changed';
    case SiteWentLive = 'site_went_live';
}
