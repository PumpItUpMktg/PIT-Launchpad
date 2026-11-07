<?php

namespace App\Filament\Pages\Operate;

use App\Enums\SocialNetwork;
use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Operator\ActiveTenant;
use App\Social\SharesBoard;
use App\Social\SocialSharer;
use Filament\Notifications\Notification;

/**
 * Operate → Shares: the tenant's social channels (a GBP per physical location, the Pages), what was
 * shared where and whether it landed, a retry for a failed share, a manual share of any published job
 * or post, and the GBP Q&A pairs of the question posts to copy onto the Business Profile by hand.
 */
class OperateShares extends OperatePage
{
    protected static ?string $slug = 'operate/shares';

    protected static ?string $navigationLabel = 'Shares';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.operate.shares';

    public ?string $siteId = null;

    // Add-channel form
    public string $newNetwork = SocialNetwork::Gbp->value;

    public ?string $newLocationId = null;

    public string $newChannelId = '';

    public string $newLabel = '';

    // Manual share
    public ?string $shareJobId = null;

    public ?string $sharePostId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return list<array<string, mixed>> */
    public function getChannelsProperty(): array
    {
        return $this->siteId === null ? [] : app(SharesBoard::class)->channels($this->siteId);
    }

    /** @return array<string, string> */
    public function getLocationOptionsProperty(): array
    {
        return $this->siteId === null ? [] : app(SharesBoard::class)->locationOptions($this->siteId);
    }

    /** @return list<array<string, mixed>> */
    public function getSharesProperty(): array
    {
        return $this->siteId === null ? [] : app(SharesBoard::class)->shares($this->siteId);
    }

    /** @return array{queued: int, sent: int, failed: int, sent_7d: int} */
    public function getCountsProperty(): array
    {
        return $this->siteId === null ? ['queued' => 0, 'sent' => 0, 'failed' => 0, 'sent_7d' => 0] : app(SharesBoard::class)->counts($this->siteId);
    }

    /** @return array{jobs: array<string, string>, posts: array<string, string>} */
    public function getShareableProperty(): array
    {
        return $this->siteId === null ? ['jobs' => [], 'posts' => []] : app(SharesBoard::class)->shareable($this->siteId);
    }

    /** @return list<array{content_id: string, title: string, question: string, answer: string}> */
    public function getGbpQaProperty(): array
    {
        return $this->siteId === null ? [] : app(SharesBoard::class)->gbpQa($this->siteId);
    }

    public function addChannel(): void
    {
        if ($this->siteId === null) {
            return;
        }
        $network = SocialNetwork::tryFrom($this->newNetwork);
        $channelId = trim($this->newChannelId);
        if ($network === null || $channelId === '') {
            Notification::make()->warning()->title('Network and the provider channel id are required')->send();

            return;
        }
        if ($network->perLocation() && ($this->newLocationId === null || $this->newLocationId === '')) {
            Notification::make()->warning()->title('A GBP channel belongs to a physical location — pick one')->send();

            return;
        }
        SocialChannel::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $this->siteId, 'network' => $network->value, 'provider_channel_id' => $channelId],
            ['location_id' => $network->perLocation() ? $this->newLocationId : ($this->newLocationId ?: null), 'label' => trim($this->newLabel) ?: null, 'enabled' => true],
        );
        $this->newChannelId = '';
        $this->newLabel = '';
        Notification::make()->success()->title('Channel saved')->send();
    }

    public function toggleChannel(string $channelId): void
    {
        $channel = $this->channel($channelId);
        if ($channel !== null) {
            $channel->forceFill(['enabled' => ! $channel->enabled])->save();
        }
    }

    public function setChannelCap(string $channelId, ?string $cap): void
    {
        $channel = $this->channel($channelId);
        if ($channel !== null) {
            $value = $cap === null || trim($cap) === '' ? null : max(0, (int) $cap);
            $channel->forceFill(['max_per_day' => $value === 0 ? null : $value])->save();
        }
    }

    public function removeChannel(string $channelId): void
    {
        $this->channel($channelId)?->delete();
    }

    public function retry(string $postId): void
    {
        if ($this->siteId === null) {
            return;
        }
        $post = SocialPost::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($postId);
        if ($post !== null) {
            app(SocialSharer::class)->retry($post);
            Notification::make()->success()->title('Queued again')->send();
        }
    }

    public function shareJob(): void
    {
        if ($this->siteId === null || $this->shareJobId === null || $this->shareJobId === '') {
            return;
        }
        $job = Job::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->with(['site', 'city', 'jobTypes'])->find($this->shareJobId);
        if ($job === null) {
            return;
        }
        $rows = app(SocialSharer::class)->shareJob($job, SocialTrigger::Manual);
        $this->shareJobId = null;
        $this->notifyQueued(count($rows));
    }

    public function sharePost(): void
    {
        if ($this->siteId === null || $this->sharePostId === null || $this->sharePostId === '') {
            return;
        }
        $post = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->with(['site', 'silo', 'renderJobs'])->find($this->sharePostId);
        if ($post === null) {
            return;
        }
        $rows = app(SocialSharer::class)->sharePost($post, SocialTrigger::Manual);
        $this->sharePostId = null;
        $this->notifyQueued(count($rows));
    }

    private function notifyQueued(int $count): void
    {
        if ($count === 0) {
            Notification::make()->warning()->title('Nothing queued')->body('No enabled channel takes this share — add or enable a channel first.')->send();

            return;
        }
        Notification::make()->success()->title("Queued {$count} share(s)")->body('They post on the worker; this page shows each one as it lands.')->send();
    }

    private function channel(string $channelId): ?SocialChannel
    {
        if ($this->siteId === null) {
            return null;
        }

        return SocialChannel::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($channelId);
    }
}
