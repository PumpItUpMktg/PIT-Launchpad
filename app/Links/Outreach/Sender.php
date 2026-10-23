<?php

namespace App\Links\Outreach;

use App\Models\Site;

/**
 * The address outreach goes out from (§ Links): the tenant's own, on the tenant's own domain, or nothing.
 * The rule is checked here at save AND at send so a sender that drifted off-domain (a changed domain_url)
 * can never be used. Mail from anywhere else — the platform, an agency inbox — is refused by design.
 */
final class Sender
{
    /** @return array{email: string, name: string}|null */
    public static function for(Site $site): ?array
    {
        $email = strtolower(trim((string) $site->outreach_from_email));
        if (! self::onOwnDomain($site, $email)) {
            return null;
        }
        $name = trim((string) $site->outreach_from_name);

        return ['email' => $email, 'name' => $name !== '' ? $name : (string) $site->brand_name];
    }

    /** True when `$email` is a valid address on the tenant's domain (or a subdomain of it). */
    public static function onOwnDomain(Site $site, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = self::domain($site);
        $at = (string) substr($email, (int) strrpos($email, '@') + 1);

        return $domain !== '' && ($at === $domain || str_ends_with($at, '.'.$domain));
    }

    /** The tenant's registrable host, lower-case, without www. */
    public static function domain(Site $site): string
    {
        return strtolower((string) preg_replace('/^www\./', '', (string) parse_url((string) $site->domain_url, PHP_URL_HOST)));
    }
}
