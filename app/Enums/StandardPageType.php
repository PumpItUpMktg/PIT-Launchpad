<?php

namespace App\Enums;

use App\Standard\StandardPageGate;

/**
 * The standard (non-service, non-location) pages every site can have. Two sets:
 *
 * - **Fixed core** ({@see isFixed()}) — always built: Home, About, Contact, Areas We Serve,
 *   Privacy, Terms. The site has to be launchable.
 * - **Optional** — accept/decline, and **data-gated**: only offered when the site has the data
 *   to fill them (see {@see StandardPageGate}).
 *
 * Each type maps to a content source + a VoiceKit-injected draft recipe ({@see contentSource()},
 * {@see recipe()}). Brand-critical pages ({@see isBrandCritical()}) require review before publish.
 */
enum StandardPageType: string
{
    // Fixed core
    case Home = 'home';
    case About = 'about';
    case Contact = 'contact';
    case AreasWeServe = 'areas_we_serve';
    case Privacy = 'privacy';
    case Terms = 'terms';

    // Optional (accept/decline + data-gated)
    case Reviews = 'reviews';
    case WhyChooseUs = 'why_choose_us';
    case Financing = 'financing';
    case Warranty = 'warranty';
    case Faq = 'faq';
    case Gallery = 'gallery';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::About => 'About',
            self::Contact => 'Contact',
            self::AreasWeServe => 'Areas We Serve',
            self::Privacy => 'Privacy Policy',
            self::Terms => 'Terms of Service',
            self::Reviews => 'Reviews',
            self::WhyChooseUs => 'Why Choose Us',
            self::Financing => 'Financing',
            self::Warranty => 'Warranty / Guarantee',
            self::Faq => 'FAQ',
            self::Gallery => 'Gallery',
            self::Team => 'Team',
        };
    }

    /** @return list<self> */
    public static function fixed(): array
    {
        return [self::Home, self::About, self::Contact, self::AreasWeServe, self::Privacy, self::Terms];
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return [self::Reviews, self::WhyChooseUs, self::Financing, self::Warranty, self::Faq, self::Gallery, self::Team];
    }

    public function isFixed(): bool
    {
        return in_array($this, self::fixed(), true);
    }

    public function isOptional(): bool
    {
        return ! $this->isFixed();
    }

    /** Brand-critical pages require operator review before publish. */
    public function isBrandCritical(): bool
    {
        return in_array($this, [self::Home, self::About, self::WhyChooseUs], true);
    }

    /** Where the page's content comes from (plain-language, for the build recipe + display). */
    public function contentSource(): string
    {
        return match ($this) {
            self::Home => 'top-silo anchor + brand + service categories + service area + CTA',
            self::About, self::WhyChooseUs => 'VoiceKit + intake USPs',
            self::Contact => 'business info + hours + GBP + form',
            self::AreasWeServe => 'the town / location layer',
            self::Reviews => 'GS Reviews',
            self::Gallery => 'Job Capture photos',
            self::Financing, self::Warranty, self::Team => 'intake config',
            self::Faq => 'generated from trade + services',
            self::Privacy, self::Terms => 'legal boilerplate template',
        };
    }

    /** The draft recipe identifier the composition pipeline keys on. */
    public function recipe(): string
    {
        return 'standard.'.$this->value;
    }
}
