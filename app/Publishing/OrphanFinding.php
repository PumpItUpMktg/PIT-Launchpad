<?php

namespace App\Publishing;

use App\Enums\OrphanType;

/**
 * One page-integrity problem found by {@see OrphanScanner} — a value object the command + any surface
 * render. `url` is the public path affected (leading slash); `contentId`/`title` identify the row when
 * one exists (a hard-deleted parent has neither).
 */
final class OrphanFinding
{
    public function __construct(
        public readonly OrphanType $type,
        public readonly string $url,
        public readonly string $title,
        public readonly ?string $contentId = null,
        public readonly string $detail = '',
    ) {}

    /**
     * @return array{type: string, label: string, url: string, title: string, content_id: string|null, detail: string, fix: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'url' => $this->url,
            'title' => $this->title,
            'content_id' => $this->contentId,
            'detail' => $this->detail,
            'fix' => $this->type->fix(),
        ];
    }
}
