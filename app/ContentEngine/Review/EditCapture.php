<?php

namespace App\ContentEngine\Review;

use App\Enums\EditReason;
use App\Models\Content;
use App\Models\ContentEdit;

/**
 * Captures the §7 quality signal at edit-time: the ORIGINAL generated text (before the edit
 * overwrites it), the edited version, a reason tag, and coordinates (field, page, silo, site,
 * user). Non-retrofittable — the original is unrecoverable once overwritten, so capture must
 * happen at the moment of save. Unchanged fields are skipped (an "edit" that changed nothing is
 * not a signal).
 */
class EditCapture
{
    /**
     * Record one field correction. Returns null when the value is unchanged (no signal).
     */
    public function record(
        Content $content,
        string $field,
        ?string $original,
        ?string $edited,
        EditReason $reason,
        ?string $userId = null,
    ): ?ContentEdit {
        if ($this->normalize($original) === $this->normalize($edited)) {
            return null;
        }

        return ContentEdit::create([
            'site_id' => $content->site_id,
            'content_id' => $content->id,
            'silo_id' => $content->silo_id,
            'user_id' => $userId,
            'field' => $field,
            'reason' => $reason,
            'original' => $original,
            'edited' => $edited,
        ]);
    }

    /**
     * Capture every changed field between a before/after flat map (field key => string value).
     * The editor builds these maps from the slots/body/SEO it's saving.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @return list<ContentEdit>
     */
    public function captureDiff(
        Content $content,
        array $before,
        array $after,
        EditReason $reason,
        ?string $userId = null,
    ): array {
        $captured = [];
        foreach ($after as $field => $editedValue) {
            $edit = $this->record($content, $field, $before[$field] ?? null, $editedValue, $reason, $userId);
            if ($edit !== null) {
                $captured[] = $edit;
            }
        }

        return $captured;
    }

    private function normalize(?string $value): string
    {
        return trim((string) $value);
    }
}
