<?php

namespace App\Filament\Resources\AiContentResource\Pages;

use App\ContentEngine\Review\ReviewActions;
use App\Filament\Resources\AiContentResource;
use App\Models\Content;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The AI-content review detail — edit kit slots / body / SEO in place before approving. Persistence
 * routes through ReviewActions::saveEdits so SEO merges into meta without clobbering image specs. The
 * same detail the blog review queue offers, shared for the GEO lane.
 */
class EditAiContent extends EditRecord
{
    protected static string $resource = AiContentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Content) {
            $seo = is_array($record->meta['seo'] ?? null) ? $record->meta['seo'] : [];
            $data['seo_title'] = $seo['title'] ?? '';
            $data['seo_meta'] = $seo['meta_description'] ?? '';
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Content) {
            return parent::handleRecordUpdate($record, $data);
        }

        if (array_key_exists('slug', $data)) {
            $record->fill(['slug' => $data['slug']]);
        }

        $edits = [];
        if (array_key_exists('slot_payload', $data)) {
            $edits['slot_payload'] = is_array($data['slot_payload']) ? $data['slot_payload'] : [];
        }
        if (array_key_exists('body', $data)) {
            $edits['body'] = $data['body'];
        }
        $edits['seo'] = [
            'title' => (string) ($data['seo_title'] ?? ''),
            'meta_description' => (string) ($data['seo_meta'] ?? ''),
        ];

        return app(ReviewActions::class)->saveEdits($record, $edits);
    }
}
