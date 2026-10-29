<?php

namespace App\Publishing\Forms;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\LeadForm;
use App\Models\Location;
use App\Models\PageConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Service;

/**
 * Which lead form a page renders. The chain, first match wins:
 *
 *   1. the page's own override (PageConfig.form_embed — a raw paste for a one-off);
 *   2. the OWNER's form — a service page takes its Service's form; an office hub and every town page the
 *      office serves (parent_location_id) take the Location's form, so one choice per office covers
 *      hundreds of pages and the CRM sees which office / product every lead came from;
 *   3. the site default (ConversionConfig.default_lead_form_id — the home page and everything else);
 *   4. the legacy single paste (ConversionConfig.form_embed) — the pre-library behaviour, so nothing
 *      changes until forms are assigned.
 *
 * Both the block composer (whether/where a form section renders) and the meta blob (the embed the
 * plugin's [lp_form] shows) read THIS, so the layout and the rendered embed always agree.
 */
final class LeadFormResolver
{
    public const SOURCE_PAGE = 'page';

    public const SOURCE_SERVICE = 'service';

    public const SOURCE_LOCATION = 'location';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_PASTE = 'paste';

    public function embedFor(Content $content): ?string
    {
        return $this->resolve($content)['embed'];
    }

    /**
     * @return array{embed: ?string, source: ?string, form: ?string} the embed, where it came from, and the library form's name
     */
    public function resolve(Content $content): array
    {
        $page = trim((string) (PageConfig::withoutGlobalScope(SiteScope::class)->where('content_id', $content->id)->value('form_embed') ?? ''));
        if ($page !== '') {
            return ['embed' => $page, 'source' => self::SOURCE_PAGE, 'form' => null];
        }

        $owner = $this->ownerForm($content);
        if ($owner !== null) {
            return ['embed' => trim((string) $owner['form']->embed), 'source' => $owner['source'], 'form' => (string) $owner['form']->name];
        }

        $config = ConversionConfig::withoutGlobalScope(SiteScope::class)->where('site_id', $content->site_id)->first();
        if ($config !== null && $config->default_lead_form_id !== null) {
            $default = $this->form((string) $content->site_id, (string) $config->default_lead_form_id);
            if ($default !== null) {
                return ['embed' => trim((string) $default->embed), 'source' => self::SOURCE_DEFAULT, 'form' => (string) $default->name];
            }
        }

        $paste = trim((string) ($config->form_embed ?? ''));
        if ($paste !== '') {
            return ['embed' => $paste, 'source' => self::SOURCE_PASTE, 'form' => null];
        }

        return ['embed' => null, 'source' => null, 'form' => null];
    }

    /** @return array{form: LeadForm, source: string}|null */
    private function ownerForm(Content $content): ?array
    {
        $siteId = (string) $content->site_id;

        if ($content->page_type === PageType::Service && $content->primary_service_id !== null) {
            $formId = Service::withoutGlobalScope(SiteScope::class)->whereKey($content->primary_service_id)->value('lead_form_id');
            $form = is_string($formId) ? $this->form($siteId, $formId) : null;

            return $form !== null ? ['form' => $form, 'source' => self::SOURCE_SERVICE] : null;
        }

        if ($content->page_type === PageType::Location) {
            // The office hub carries location_id; a town page carries the serving office as parent_location_id.
            $locationId = $content->location_id ?? $content->parent_location_id;
            if ($locationId === null) {
                return null;
            }
            $formId = Location::withoutGlobalScope(SiteScope::class)->whereKey($locationId)->value('lead_form_id');
            $form = is_string($formId) ? $this->form($siteId, $formId) : null;

            return $form !== null ? ['form' => $form, 'source' => self::SOURCE_LOCATION] : null;
        }

        return null;
    }

    private function form(string $siteId, string $id): ?LeadForm
    {
        $form = LeadForm::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereKey($id)->first();

        return $form !== null && trim((string) $form->embed) !== '' ? $form : null;
    }
}
