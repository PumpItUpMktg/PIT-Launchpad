<?php

namespace App\Social\Provider;

use App\Models\SocialChannel;
use App\Models\SocialPost;

/**
 * The posting transport seam. The default binding is {@see MockSocialProvider}; the SoMePoster adapter
 * binds here once its API key exists. Composition, routing and approval all live on our side — a
 * provider only receives finished copy + image + link for one channel and returns the provider's post id.
 */
interface SocialProvider
{
    /** @throws SocialProviderException when the provider refuses or the transport fails */
    public function publish(SocialChannel $channel, SocialPost $post): SocialPublishResult;
}
