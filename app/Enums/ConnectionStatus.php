<?php

namespace App\Enums;

/**
 * Lifecycle of a per-tenant connection (the OAuth-backed ones especially):
 * connected and token-valid, or in a state that needs operator/client action.
 * Stored in the existing `connections.status` string column — promoting that
 * column to this cast + a queryable index is the flagged §1 follow-up.
 */
enum ConnectionStatus: string
{
    case Active = 'active';            // legacy default for static-key connections
    case Connected = 'connected';      // OAuth grant valid, tokens present
    case NeedsReconnect = 'needs_reconnect'; // refresh failed / revoked — reconnect
    case Revoked = 'revoked';          // grant explicitly revoked
}
