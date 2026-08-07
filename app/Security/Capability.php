<?php

namespace App\Security;

use App\Enums\UserRole;

/**
 * The platform's operational capabilities — the vocabulary of "who may do what," decoupled from the
 * coarse {@see UserRole}. Every guarded action (a Filament button, a console command, a
 * policy) names the capability it needs; {@see RoleCapabilities} maps roles → the capabilities they
 * hold. This is the single spine both the internal Super Admin surfaces and the client-side Site Admin
 * surfaces consult, so the split lives in ONE place instead of scattered `=== UserRole::Operator` checks.
 *
 * The dividing line: OPERATE capabilities are the day-to-day running of a site (a Site Admin may hold
 * them). RECOVER + ADMIN capabilities are the backend corrections and sensitive administration that only
 * the internal Super Admin tier ever holds — "anything the Admin could break," the Super Admin fixes.
 */
enum Capability: string
{
    // ── OPERATE — the day-to-day running of a site (Site Admin may hold these) ──
    case ViewDashboards = 'operate.view_dashboards';
    case EditContent = 'operate.edit_content';
    case ApproveContent = 'operate.approve_content';
    case GenerateContent = 'operate.generate_content';
    case PublishContent = 'operate.publish_content';

    // ── RECOVER — backend corrections that clear stuck state (Super Admin only) ──
    case UnfreezeQueue = 'recover.unfreeze_queue';       // drain the queue, clear failed jobs
    case ResetRenders = 'recover.reset_renders';         // requeue render_failed images
    case ResetPublishing = 'recover.reset_publishing';   // clear stuck rendering/publishing/failed states
    case UnlockPage = 'recover.unlock_page';             // clear locked / locally_edited publish protection

    // ── ADMIN — sensitive administration (Super Admin only) ──
    case ManageCredentials = 'admin.manage_credentials'; // reveal / rotate per-tenant secrets
    case ManageUsers = 'admin.manage_users';             // create/invite users, assign roles + sites
    case ManageTenantLifecycle = 'admin.tenant_lifecycle'; // reset / delete / suspend / launch a tenant
    case ManageEngineControls = 'admin.engine_controls'; // budget ceiling, cadence, feeds, voice activation
    case PreviewAsAdmin = 'admin.preview_as';            // the read-only "view exactly what the Site Admin sees" toggle

    /** Coarse grouping for admin UIs (the users page, the corrections console, a capability reference). */
    public function group(): string
    {
        return match ($this) {
            self::ViewDashboards, self::EditContent, self::ApproveContent,
            self::GenerateContent, self::PublishContent => 'operate',
            self::UnfreezeQueue, self::ResetRenders, self::ResetPublishing, self::UnlockPage => 'recover',
            self::ManageCredentials, self::ManageUsers, self::ManageTenantLifecycle,
            self::ManageEngineControls, self::PreviewAsAdmin => 'admin',
        };
    }

    /** Short human label for admin surfaces. */
    public function label(): string
    {
        return match ($this) {
            self::ViewDashboards => 'View dashboards',
            self::EditContent => 'Edit content & SEO',
            self::ApproveContent => 'Approve content',
            self::GenerateContent => 'Generate content (AI)',
            self::PublishContent => 'Publish to WordPress',
            self::UnfreezeQueue => 'Unfreeze / drain the queue',
            self::ResetRenders => 'Reset failed image renders',
            self::ResetPublishing => 'Reset stuck publishing',
            self::UnlockPage => 'Unlock a protected page',
            self::ManageCredentials => 'Reveal / rotate credentials',
            self::ManageUsers => 'Manage users & access',
            self::ManageTenantLifecycle => 'Reset / delete / launch a tenant',
            self::ManageEngineControls => 'Engine controls (budget, cadence, feeds, voice)',
            self::PreviewAsAdmin => 'Preview as the Site Admin',
        };
    }
}
