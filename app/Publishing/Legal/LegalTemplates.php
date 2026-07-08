<?php

namespace App\Publishing\Legal;

/**
 * Honest, deterministic legal boilerplate for the Privacy Policy and Terms of Service pages —
 * parameterized with the tenant's REAL data (business name, site URL, contact), never AI-generated
 * (that would risk fabricated terms — false representations of the business's legal commitments).
 *
 * The language is conservative and universally-true of a small-business service website: optional
 * practices are phrased with "may" (never asserting a practice the business might not follow), and
 * governing law refers to "the state in which {business} operates" rather than guessing a state. It
 * is a starting template the operator reviews before publish — not a substitute for counsel — so it
 * states only what is safe and generic, filling in nothing it cannot ground in tenant data.
 */
final class LegalTemplates
{
    /**
     * @return array{title: string, effective_date: string, sections: list<array{heading: string, paragraphs: list<string>}>}
     */
    public function privacy(LegalContext $ctx): array
    {
        $business = $ctx->business;
        $site = $ctx->siteLabel();

        return [
            'title' => 'Privacy Policy',
            'effective_date' => $ctx->effectiveDate,
            'sections' => [
                [
                    'heading' => 'Overview',
                    'paragraphs' => [
                        "This Privacy Policy explains how {$business} (\"we\", \"us\") handles information collected through {$site}. By using this website, you agree to the practices described here.",
                    ],
                ],
                [
                    'heading' => 'Information we collect',
                    'paragraphs' => [
                        'We may collect information you provide directly — such as your name, phone number, email address, and the details of your request — when you contact us, request an estimate, or fill out a form on this website.',
                        'We may also automatically collect standard technical information, such as your browser type, device, and pages visited, through cookies and similar analytics tools that help us understand how the website is used.',
                    ],
                ],
                [
                    'heading' => 'How we use your information',
                    'paragraphs' => [
                        'We use the information you provide to respond to your inquiries, schedule and provide services, and communicate with you about your request. We use technical and analytics information to operate, maintain, and improve the website.',
                    ],
                ],
                [
                    'heading' => 'How we share information',
                    'paragraphs' => [
                        'We do not sell your personal information. We may share information with service providers who help us operate the website or deliver our services, and where required by law. Any such providers are expected to handle your information consistent with this policy.',
                    ],
                ],
                [
                    'heading' => 'Cookies',
                    'paragraphs' => [
                        'This website may use cookies to remember your preferences and measure site usage. You can set your browser to refuse cookies, though some parts of the website may not function as intended if you do.',
                    ],
                ],
                [
                    'heading' => 'Your choices',
                    'paragraphs' => [
                        'You may request to access, correct, or delete the personal information you have provided to us, or ask us to stop contacting you, by reaching out using the contact details below.',
                    ],
                ],
                [
                    'heading' => 'Contact us',
                    'paragraphs' => [
                        $ctx->contactSentence('privacy'),
                    ],
                ],
                [
                    'heading' => 'Changes to this policy',
                    'paragraphs' => [
                        'We may update this Privacy Policy from time to time. Any changes will be posted on this page with a revised effective date.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{title: string, effective_date: string, sections: list<array{heading: string, paragraphs: list<string>}>}
     */
    public function terms(LegalContext $ctx): array
    {
        $business = $ctx->business;
        $site = $ctx->siteLabel();

        return [
            'title' => 'Terms of Service',
            'effective_date' => $ctx->effectiveDate,
            'sections' => [
                [
                    'heading' => 'Acceptance of terms',
                    'paragraphs' => [
                        "These Terms of Service govern your use of {$site}, operated by {$business}. By accessing or using this website, you agree to these terms. If you do not agree, please do not use the website.",
                    ],
                ],
                [
                    'heading' => 'Use of the website',
                    'paragraphs' => [
                        'The content on this website is provided for general informational purposes about our services. You agree to use the website only for lawful purposes and not in any way that could damage, disable, or impair it.',
                    ],
                ],
                [
                    'heading' => 'Services and estimates',
                    'paragraphs' => [
                        'Any description of services, pricing guidance, or availability on this website is general information, not a binding offer. Work we perform is governed by the separate written estimate or agreement we provide for your specific project.',
                    ],
                ],
                [
                    'heading' => 'Intellectual property',
                    'paragraphs' => [
                        "The content, branding, and materials on this website are the property of {$business} or its licensors and may not be copied or reused without permission.",
                    ],
                ],
                [
                    'heading' => 'Disclaimer of warranties',
                    'paragraphs' => [
                        'This website is provided on an "as is" and "as available" basis without warranties of any kind, whether express or implied. We do not warrant that the website will be uninterrupted, error-free, or free of harmful components.',
                    ],
                ],
                [
                    'heading' => 'Limitation of liability',
                    'paragraphs' => [
                        "To the fullest extent permitted by law, {$business} will not be liable for any indirect, incidental, or consequential damages arising out of your use of this website.",
                    ],
                ],
                [
                    'heading' => 'Governing law',
                    'paragraphs' => [
                        "These terms are governed by the laws of the state in which {$business} operates, without regard to its conflict-of-law provisions.",
                    ],
                ],
                [
                    'heading' => 'Contact us',
                    'paragraphs' => [
                        $ctx->contactSentence('terms'),
                    ],
                ],
                [
                    'heading' => 'Changes to these terms',
                    'paragraphs' => [
                        'We may update these Terms of Service from time to time. Changes take effect when posted on this page with a revised effective date; your continued use of the website constitutes acceptance.',
                    ],
                ],
            ],
        ];
    }
}
