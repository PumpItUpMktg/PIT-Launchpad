<?php
/**
 * The authed receiver for the Job Capture control plane. Registers the SAME `launchpad/v1/job` +
 * `/job/delete` contract the companion plugin exposes, so the control plane's push works against a
 * standalone site with no change. Auth is a standard WordPress Application Password (Basic auth) resolving
 * to a user who can edit posts.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

final class Rest
{
    private const NS = 'launchpad/v1';

    public function register(): void
    {
        $auth = [$this, 'can_manage'];

        register_rest_route(self::NS, '/job', [
            'methods' => 'POST',
            'callback' => [$this, 'job'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/job/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'delete_job'],
            'permission_callback' => $auth,
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('edit_posts');
    }

    public function job(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new JobStore() )->upsert((array) $request->get_json_params());

        return new WP_REST_Response($result, ($result['status'] ?? '') === 'error' ? 422 : 200);
    }

    public function delete_job(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $result = ( new JobStore() )->delete((string) ($params['job_id'] ?? ''));

        if (! empty($result['deleted'])) {
            $status = 200;
        } elseif (($result['error'] ?? '') === 'job_id required') {
            $status = 422;
        } else {
            $status = 500;
        }

        return new WP_REST_Response($result, $status);
    }
}
