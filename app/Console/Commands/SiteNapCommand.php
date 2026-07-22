<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\SiteContact;
use Illuminate\Console\Command;

/**
 * Read-only doctor for the SITE-WIDE (corporate) NAP that the header/footer chrome renders. It answers
 * the recurring "why is a physical location's phone/address in the blog header/footer?" by showing what
 * was captured as corporate (sites.phone + corporate_* from the Business step), what the chrome will
 * actually resolve via {@see SiteContact}, and — critically — whether each value is the CORPORATE one or
 * a fall-back to the earliest Location (which is what puts e.g. Montclair in the chrome when corporate
 * was left blank). It touches nothing; it just explains.
 */
class SiteNapCommand extends Command
{
    protected $signature = 'launchpad:site-nap {site : Site id or brand name}';

    protected $description = 'Show the corporate (chrome) NAP a site resolves and whether it falls back to a physical location.';

    public function handle(SiteContact $contact): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))
            ->orWhere('brand_name', $this->argument('site'))
            ->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $earliest = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->first();

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — header/footer chrome NAP");
        $this->newLine();

        $this->line('<comment>Captured corporate (Business step)</comment>');
        $this->line('  sites.phone: '.$this->show((string) $site->phone));
        $this->line('  emergency_phone: '.$this->show((string) $site->emergency_phone));
        $this->line('  corporate address: '.$this->show((string) $site->corporateAddressLine()));

        $this->newLine();
        $this->line('<comment>What the chrome will render</comment>');
        $phone = (string) $contact->phone($site);
        $address = (string) $contact->address($site);
        $phoneCorporate = trim((string) $site->phone) !== '';
        $addressCorporate = trim((string) $site->corporateAddressLine()) !== '';

        $this->line('  phone: '.$this->show($phone).'  '.$this->sourceTag($phoneCorporate, $earliest));
        $this->line('  address: '.$this->show($address).'  '.$this->sourceTag($addressCorporate, $earliest));

        if ($earliest !== null) {
            $this->newLine();
            $this->line('<comment>Fallback source — earliest Location</comment>');
            $this->line("  {$earliest->name}: ".$this->show((string) $earliest->phone).'  ·  '.$this->show((string) $earliest->address));
        }

        $this->newLine();
        $usesFallback = ! $phoneCorporate || ! $addressCorporate;
        if ($usesFallback && $earliest !== null) {
            $this->warn("  ⇒ The chrome is falling back to the \"{$earliest->name}\" location because the corporate "
                .(! $phoneCorporate && ! $addressCorporate ? 'phone and address are' : (! $phoneCorporate ? 'phone is' : 'address is'))
                .' blank. Fill the corporate phone/address on Setup → Business, then re-sync the chrome (Portfolio → Sync header/footer chrome).');
        } elseif ($usesFallback) {
            $this->warn('  ⇒ Corporate NAP is blank and there is no location to fall back to — capture it on Setup → Business, then re-sync the chrome.');
        } else {
            $this->info('  ⇒ Corporate NAP is captured. If a location still shows on the live blog, the pushed chrome is stale — re-sync it (Portfolio → Sync header/footer chrome).');
        }

        return self::SUCCESS;
    }

    private function sourceTag(bool $corporate, ?Location $earliest): string
    {
        if ($corporate) {
            return '<info>[corporate]</info>';
        }

        return $earliest !== null
            ? "<error>[fallback → {$earliest->name}]</error>"
            : '<error>[none captured]</error>';
    }

    private function show(string $value): string
    {
        return trim($value) !== '' ? trim($value) : '— (blank)';
    }
}
