<?php

namespace App\Filament\Pages;

use App\ContentEngine\Review\ProofPreview;
use App\ContentEngine\Review\ReviewActions;
use App\ContentEngine\Review\StrategyRail;
use App\Enums\ContentStatus;
use App\Enums\EditReason;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Grow;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The proof editor — the [Review] target for a generated page. It renders the page the way it will
 * read to a visitor (the structured §4 preview: the kit's blocks in order, the real copy, the brand
 * kit) beside the strategy rail (placement / target / performance), so the operator scans top-to-
 * bottom and approves. The rare off-base / off-brand block is corrected in place; every saved edit
 * carries a one-tap reason that feeds the §7 quality signal. Approve flips to approved (no WP);
 * Publish runs §2's compose-and-push.
 *
 * Operator-only. Reached from Grow's [Review]; not in the nav.
 *
 * @property-read array<string, mixed> $preview
 * @property-read array<string, mixed> $rail
 */
class ProofEditor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static ?string $slug = 'proof';

    protected string $view = 'filament.pages.proof-editor';

    public ?string $contentId = null;

    /** The slot key currently being corrected in place (null = read mode). */
    public ?string $editingKey = null;

    public string $editValue = '';

    /** The one-tap reason for the open edit (an EditReason value), required before save. */
    public ?string $editReason = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->contentId = request()->query('content');

        abort_if($this->record() === null, 404);
    }

    public function getTitle(): string
    {
        $record = $this->record();

        return $record === null ? 'Review' : (string) $record->title;
    }

    /** @return array<string, mixed> */
    public function getPreviewProperty(): array
    {
        $record = $this->record();

        return $record === null ? ['brand' => [], 'sections' => [], 'seo' => [], 'permalink' => ''] : app(ProofPreview::class)->for($record);
    }

    /** @return array<string, mixed> */
    public function getRailProperty(): array
    {
        $record = $this->record();

        return $record === null ? [] : app(StrategyRail::class)->for($record);
    }

    public function backUrl(): string
    {
        return Grow::getUrl();
    }

    /** Open the inline editor for one section (only editable, non-image slots reach here). */
    public function startEdit(string $key): void
    {
        $section = collect($this->preview['sections'])->firstWhere('key', $key);
        if ($section === null || $section['editable'] !== true || $section['is_image'] === true) {
            return;
        }

        $this->editingKey = $key;
        $this->editValue = is_string($section['value']) ? $section['value'] : '';
        $this->editReason = null;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingKey', 'editValue', 'editReason']);
    }

    /** Save one corrected block — the reason is mandatory so the §7 signal is never lost. */
    public function saveEdit(): void
    {
        $record = $this->record();
        $reason = $this->editReason !== null ? EditReason::tryFrom($this->editReason) : null;

        if ($record === null || $this->editingKey === null) {
            return;
        }

        if ($reason === null) {
            Notification::make()->warning()->title('Pick a reason')
                ->body('A one-tap reason keeps the quality signal honest.')->send();

            return;
        }

        $payload = is_array($record->slot_payload) ? $record->slot_payload : [];
        $payload[$this->editingKey] = $this->editValue;

        app(ReviewActions::class)->saveEdits($record, ['slot_payload' => $payload], $reason, Auth::id());

        $this->reset(['editingKey', 'editValue', 'editReason']);
        Notification::make()->success()->title('Saved')->send();
    }

    /** Approve — a cheap state flip to `approved` (no WordPress contact). */
    public function approve(): void
    {
        $record = $this->record();
        if ($record === null) {
            return;
        }

        $result = app(ReviewActions::class)->approve($record, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot approve')->body((string) $result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Approved — ready to publish')
            ->body($result->warnings !== [] ? implode(' ', $result->warnings) : null)->send();
    }

    /** Publish — §2's idempotent compose-and-push to WordPress. */
    public function publish(): void
    {
        $record = $this->record();
        if ($record === null) {
            return;
        }

        $result = app(ReviewActions::class)->publish($record, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body((string) $result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Publishing — composing and pushing to WordPress')
            ->body($result->warnings !== [] ? implode(' ', $result->warnings) : null)->send();
    }

    /** The page being reviewed, operator-scoped (cross-tenant read is fine for an operator). */
    public function record(): ?Content
    {
        if ($this->contentId === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);
    }

    /** Whether the morphing primary should offer Publish (drafted + approved) vs Approve. */
    public function canPublish(): bool
    {
        return $this->record()?->status === ContentStatus::Approved;
    }

    /** Whether this page is still a draft awaiting approval. */
    public function isApprovable(): bool
    {
        $record = $this->record();

        return $record !== null && $record->hasDraft()
            && ! in_array($record->status, [ContentStatus::Approved, ContentStatus::Published], true);
    }
}
