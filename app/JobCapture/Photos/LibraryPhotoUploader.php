<?php

namespace App\JobCapture\Photos;

use App\Models\Account;
use App\Models\LibraryPhoto;
use App\Publishing\TenantStorage;

/**
 * Adds an image to an account's reusable photo library (§ Job Capture). Stores the source original verbatim
 * under the account/library R2 prefix (the geotagging happens later, per attachment, not on the original) and
 * records a {@see LibraryPhoto}. Deduped by content hash within the account, so re-uploading the same file
 * returns the existing row instead of a duplicate.
 */
final class LibraryPhotoUploader
{
    public function __construct(private readonly TenantStorage $storage) {}

    public function upload(Account $account, string $bytes, ?string $filename = null, ?string $userId = null): LibraryPhoto
    {
        $hash = hash('sha256', $bytes);

        $existing = LibraryPhoto::query()
            ->where('account_id', $account->id)->where('hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $info = @getimagesizefromstring($bytes) ?: [];
        $mime = is_string($info['mime'] ?? null) ? $info['mime'] : null;
        $key = $this->storage->putForLibrary($account, $hash.'.'.$this->extension($mime), $bytes);

        return LibraryPhoto::create([
            'account_id' => $account->id,
            'created_by_user_id' => $userId,
            'r2_key' => $key,
            'hash' => $hash,
            'original_filename' => $filename,
            'content_type' => $mime,
            'width' => isset($info[0]) ? (int) $info[0] : null,
            'height' => isset($info[1]) ? (int) $info[1] : null,
            'byte_size' => strlen($bytes),
        ]);
    }

    private function extension(?string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
