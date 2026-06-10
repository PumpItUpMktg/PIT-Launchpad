<?php

namespace App\ContentEngine\Drafting;

use Anthropic\Core\Exceptions\APIException;
use Illuminate\Support\Str;
use Throwable;

/**
 * The structured cause of a draft failure — the detail that "returned no
 * content" was throwing away. Two shapes: the drafter call THREW (transport /
 * HTTP / auth — carries the exception class, message, and Anthropic HTTP status),
 * or it RETURNED but the response didn't parse into a draft (carries a truncated
 * excerpt of the raw model output). Persisted into the failure marker and logged.
 */
final class DraftFailure
{
    private const RAW_EXCERPT_LIMIT = 1000;

    private const MESSAGE_LIMIT = 500;

    private function __construct(
        public readonly string $reason,
        public readonly ?string $exceptionClass,
        public readonly ?string $exceptionMessage,
        public readonly ?int $httpStatus,
        public readonly ?string $rawResponseExcerpt,
    ) {}

    public static function fromException(Throwable $e): self
    {
        return new self(
            reason: 'The drafter call threw before returning content.',
            exceptionClass: $e::class,
            exceptionMessage: Str::limit(self::oneLine($e->getMessage()), self::MESSAGE_LIMIT),
            httpStatus: self::httpStatusFrom($e),
            rawResponseExcerpt: null,
        );
    }

    public static function emptyResponse(string $rawResponse): self
    {
        return new self(
            reason: 'The drafter returned no usable content (empty body/slots) — the model response did not parse into a draft.',
            exceptionClass: null,
            exceptionMessage: null,
            httpStatus: null,
            rawResponseExcerpt: self::excerpt($rawResponse),
        );
    }

    /**
     * A single human line for the marker/notification — reason + the most useful
     * cause fields, bounded.
     */
    public function summary(): string
    {
        $parts = [$this->reason];

        if ($this->httpStatus !== null) {
            $parts[] = "HTTP {$this->httpStatus}";
        }

        if ($this->exceptionClass !== null) {
            $detail = class_basename($this->exceptionClass);
            if ($this->exceptionMessage !== null && $this->exceptionMessage !== '') {
                $detail .= ': '.$this->exceptionMessage;
            }
            $parts[] = $detail;
        }

        if ($this->rawResponseExcerpt !== null && $this->rawResponseExcerpt !== '') {
            $parts[] = 'raw≈"'.Str::limit(self::oneLine($this->rawResponseExcerpt), 160).'"';
        }

        return implode(' — ', $parts);
    }

    /**
     * The structured marker persisted to `meta.draft_failure`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'http_status' => $this->httpStatus,
            'raw_response_excerpt' => $this->rawResponseExcerpt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'reason' => $this->reason,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'anthropic_http_status' => $this->httpStatus,
            'raw_response_excerpt' => $this->rawResponseExcerpt,
        ];
    }

    /**
     * Best-effort Anthropic HTTP status: the SDK's APIException carries a public
     * `status`; the OAuth path carries `statusCode`. Anything else has none.
     */
    private static function httpStatusFrom(Throwable $e): ?int
    {
        if ($e instanceof APIException && $e->status !== null) {
            return $e->status;
        }

        if (property_exists($e, 'statusCode') && is_int($e->statusCode)) {
            return $e->statusCode;
        }

        return null;
    }

    private static function excerpt(string $raw): string
    {
        return Str::limit(trim($raw), self::RAW_EXCERPT_LIMIT);
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)));
    }
}
