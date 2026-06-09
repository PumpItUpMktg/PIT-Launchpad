<?php

namespace App\Filament\Client\Resources\NewsSourceResource\Pages;

use App\Client\ClientContext;
use App\ContentEngine\Feeds\FeedValidator;
use App\Enums\FeedOrigin;
use App\Enums\SourceType;
use App\Filament\Client\Resources\NewsSourceResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateNewsSource extends CreateRecord
{
    protected static string $resource = NewsSourceResource::class;

    /** @var list<string> */
    private array $previewSamples = [];

    private ?string $previewPublisher = null;

    /**
     * Validate-on-add: before the row is created, fetch the URL (host-branched,
     * so a client feed never touches the consent wall), confirm it is real
     * RSS/Atom with items, and stash the preview. A failure blocks the save with
     * the precise reason on the URL field. Then stamp the client-owned provenance.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $site = app(ClientContext::class)->site();
        abort_if($site === null, 403);

        $url = trim((string) ($data['url'] ?? ''));
        $preview = app(FeedValidator::class)->validate($site->id, $url);

        if (! $preview->valid) {
            throw ValidationException::withMessages(['data.url' => (string) $preview->error]);
        }

        $this->previewPublisher = $preview->publisher;
        $this->previewSamples = $preview->samples;

        $data['site_id'] = $site->id;
        $data['origin'] = FeedOrigin::Client->value;
        $data['type'] = SourceType::RssFeed->value;
        $data['enabled'] = true;
        $data['label'] = trim((string) ($data['label'] ?? '')) !== ''
            ? $data['label']
            : ($preview->publisher ?? (string) (parse_url($url, PHP_URL_HOST) ?: 'News source'));

        return $data;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Added '.($this->previewPublisher ?? 'news source'))
            ->body($this->previewSamples !== []
                ? 'Recent: '.implode(' · ', array_slice($this->previewSamples, 0, 3))
                : 'We will start pulling articles on the next sweep.')
            ->success()
            ->send();
    }
}
