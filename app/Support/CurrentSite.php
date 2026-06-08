<?php

namespace App\Support;

/**
 * Resolves the current tenant (Site) for the request/process lifecycle.
 *
 * How the site is *selected* (subdomain, header, operator switch) is decided
 * in a later section. This class is deliberately thin and swappable: callers
 * only depend on CurrentSite::id(). Register as a singleton so the resolved
 * value is shared for the duration of the request.
 */
class CurrentSite
{
    protected ?string $siteId = null;

    public function setId(?string $siteId): static
    {
        $this->siteId = $siteId;

        return $this;
    }

    public function getId(): ?string
    {
        return $this->siteId;
    }

    public function forget(): void
    {
        $this->siteId = null;
    }

    /**
     * Run a callback with the given site bound as the current tenant,
     * restoring the previous value afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function use(?string $siteId, callable $callback): mixed
    {
        $previous = $this->siteId;
        $this->siteId = $siteId;

        try {
            return $callback();
        } finally {
            $this->siteId = $previous;
        }
    }

    public static function id(): ?string
    {
        return app(self::class)->getId();
    }

    public static function set(?string $siteId): void
    {
        app(self::class)->setId($siteId);
    }

    public static function clear(): void
    {
        app(self::class)->forget();
    }
}
