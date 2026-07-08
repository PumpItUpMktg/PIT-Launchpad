<?php
/**
 * Registers the authed REST contract under the launchpad/v1 namespace. All
 * writes are upserts keyed on the control-plane id. Authentication is via
 * WordPress application passwords; authorization via the service-user cap.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Rest;

use Launchpad\Companion\Content\BrandKitStore;
use Launchpad\Companion\Content\StyleStore;
use Launchpad\Companion\Content\ContentStore;
use Launchpad\Companion\Content\KitTemplateStore;
use Launchpad\Companion\Content\RedirectStore;
use Launchpad\Companion\Content\SiloStore;
use Launchpad\Companion\Content\SiteProfileStore;
use Launchpad\Companion\ServiceUser;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

final class Routes
{
    private const NS = 'launchpad/v1';

    public function register(): void
    {
        $auth = [ServiceUser::class, 'can_manage'];

        register_rest_route(self::NS, '/silo', [
            'methods' => 'POST',
            'callback' => [$this, 'silo'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/content', [
            'methods' => 'POST',
            'callback' => [$this, 'content'],
            'permission_callback' => $auth,
        ]);

        // Force-delete a Launchpad-managed post by its control-plane ULID. A POST (not core DELETE) on the
        // authed launchpad/v1 namespace: the service user carries lp_manage_content (the cap publishing
        // already uses) but NOT WordPress's core delete_post capability, and this also sidesteps security
        // plugins / WAFs that block the core REST DELETE method. Keyed on the ULID so it only ever removes
        // a post the control plane owns, and stays idempotent (already-absent = success).
        register_rest_route(self::NS, '/content/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'delete_content'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/redirects', [
            'methods' => 'POST',
            'callback' => [$this, 'redirects'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/kit-template', [
            'methods' => 'POST',
            'callback' => [$this, 'kit_template'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/brand-kit', [
            'methods' => 'POST',
            'callback' => [$this, 'brand_kit'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/style', [
            'methods' => 'POST',
            'callback' => [$this, 'style'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/templates', [
            'methods' => 'GET',
            'callback' => [$this, 'templates'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/site-profile', [
            'methods' => 'POST',
            'callback' => [$this, 'site_profile'],
            'permission_callback' => $auth,
        ]);
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response(Status::payload(), 200);
    }

    public function templates(): WP_REST_Response
    {
        return new WP_REST_Response(Templates::payload(), 200);
    }

    public function silo(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new SiloStore() )->ensure((array) $request->get_json_params());

        return new WP_REST_Response($result, 200);
    }

    public function content(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new ContentStore() )->upsert((array) $request->get_json_params());

        return new WP_REST_Response($result, 200);
    }

    public function delete_content(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $result = ( new ContentStore() )->delete((string) ($params['content_id'] ?? ''));

        // 422 for a missing id (bad request), 500 if WordPress refused the delete, 200 on success
        // (including an already-absent post — idempotent).
        if (! empty($result['deleted'])) {
            $status = 200;
        } elseif (($result['error'] ?? '') === 'content_id required') {
            $status = 422;
        } else {
            $status = 500;
        }

        return new WP_REST_Response($result, $status);
    }

    public function redirects(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $redirects = isset($params['redirects']) && is_array($params['redirects']) ? $params['redirects'] : [];

        $count = ( new RedirectStore() )->upsert($redirects);

        return new WP_REST_Response(['count' => $count], 200);
    }

    public function kit_template(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new KitTemplateStore() )->install((array) $request->get_json_params());

        return new WP_REST_Response($result, isset($result['error']) ? 422 : 200);
    }

    public function brand_kit(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new BrandKitStore() )->install((array) $request->get_json_params());

        // A missing/empty kit is a soft failure (422) the engine surfaces but does
        // not treat as a hard error — provisioning continues.
        return new WP_REST_Response($result, empty($result['updated']) ? 422 : 200);
    }

    public function style(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new StyleStore() )->apply((array) $request->get_json_params());

        return new WP_REST_Response($result, empty($result['updated']) ? 422 : 200);
    }

    public function site_profile(WP_REST_Request $request): WP_REST_Response
    {
        $result = ( new SiteProfileStore() )->save((array) $request->get_json_params());

        return new WP_REST_Response($result, empty($result['updated']) ? 422 : 200);
    }
}
