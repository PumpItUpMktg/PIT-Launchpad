<?php

namespace App\Pages;

/**
 * One page's state rendered for one audience: the resolved {@see PageState}, its sacred client line,
 * the audience's whose-move line, the badge tone, and — operator only — the append-only diagnostic
 * tail. The single shape both the operator screen (now) and the client screen (later) read from.
 */
final class PagePresentation
{
    public function __construct(
        public readonly PageState $state,
        public readonly string $clientLine,
        public readonly string $whoseMove,
        public readonly string $tone,
        public readonly ?string $operatorTail = null,
    ) {}
}
