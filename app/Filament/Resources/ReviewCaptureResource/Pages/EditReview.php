<?php

namespace App\Filament\Resources\ReviewCaptureResource\Pages;

use App\Filament\Resources\ReviewCaptureResource;
use Filament\Resources\Pages\EditRecord;

class EditReview extends EditRecord
{
    protected static string $resource = ReviewCaptureResource::class;

    /**
     * A review with an assigned location is no longer "needs location". Kept at the persistence boundary so it
     * holds however the operator reaches this form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['needs_location'] = empty($data['location_id']);

        return $data;
    }
}
