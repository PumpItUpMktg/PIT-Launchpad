<?php

namespace App\Interview\Conversation;

/**
 * One turn in the owner interview — either the assistant interviewer asking a
 * question or the owner answering. Round-trips to array so a Livewire component or
 * console loop can hold the transcript across requests and rebuild a session.
 */
final class Turn
{
    public const ASSISTANT = 'assistant';

    public const OWNER = 'owner';

    public function __construct(
        public readonly string $role,
        public readonly string $text,
    ) {}

    public static function assistant(string $text): self
    {
        return new self(self::ASSISTANT, $text);
    }

    public static function owner(string $text): self
    {
        return new self(self::OWNER, $text);
    }

    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }

    /**
     * @return array{role: string, text: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'text' => $this->text];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $role = ($data['role'] ?? self::OWNER) === self::ASSISTANT ? self::ASSISTANT : self::OWNER;

        return new self($role, trim((string) ($data['text'] ?? '')));
    }
}
