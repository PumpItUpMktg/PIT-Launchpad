<?php

namespace App\Filament\Console\Concerns;

use App\Filament\Console\Pages\ConsolePage;
use App\OpsConsole\PostImageSwapper;
use App\Security\Capability;
use Filament\Notifications\Notification;
use Livewire\WithFileUploads;

/**
 * The "swap this post's photo" upload control for the console blog pages. A single upload at a time:
 * `startSwap($id)` targets a card, its file input binds `$photoUpload`, and `updatedPhotoUpload()`
 * validates the image and hands it to {@see PostImageSwapper} (resize → R2 → variants → vision alt →
 * attach to the hero RenderJob). Gated on the EDIT-content capability. The host page must extend
 * {@see ConsolePage} (for `can()` + `ownedContent()`).
 */
trait SwapsPostImage
{
    use WithFileUploads;

    /** The uploaded photo, bound to the card's file input. */
    public mixed $photoUpload = null;

    /** The content id whose image is being swapped (drives which card shows the picker). */
    public ?string $swapFor = null;

    public function startSwap(string $contentId): void
    {
        $this->swapFor = $contentId;
        $this->photoUpload = null;
    }

    public function cancelSwap(): void
    {
        $this->swapFor = null;
        $this->photoUpload = null;
    }

    public function updatedPhotoUpload(): void
    {
        if (! $this->can(Capability::EditContent) || $this->swapFor === null || $this->photoUpload === null) {
            $this->cancelSwap();

            return;
        }

        $this->validate(['photoUpload' => ['image', 'max:8192']]);

        $post = $this->ownedContent($this->swapFor);
        if ($post === null) {
            $this->cancelSwap();

            return;
        }

        $extension = strtolower($this->photoUpload->getClientOriginalExtension() ?: (string) $this->photoUpload->guessExtension());
        app(PostImageSwapper::class)->swap($post, (string) $this->photoUpload->get(), $extension);

        $this->cancelSwap();
        Notification::make()->title('Photo swapped — resized, renamed, alt-texted. Repush to push it live.')->success()->send();
    }
}
