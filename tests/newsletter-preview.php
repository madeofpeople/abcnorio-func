<?php

use abcnorio\CustomFunc\Blocks\NewsletterSignup;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

foreach ([
    [],
    ['header' => "News \u{2014} caf\u{00E9}", 'subHeader' => 'A & B', 'submitText' => 'Join', 'requiredIndicatorText' => 'Required'],
    ['header' => '', 'subHeader' => '', 'submitText' => '', 'showRequiredIndicator' => false, 'fieldTags' => ['EMAIL', 'OTHER'], 'groupIds' => ['one']],
    ['requiredIndicatorText' => '0'],
] as $attributes) {
    $html = NewsletterSignup::render($attributes);
    $dom = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $header = $xpath->query('//*[@data-newsletter-bind="header"]')->item(0);
    $submit = $xpath->query('//*[@data-newsletter-bind="submit"]')->item(0);
    $hasSubtitle = $xpath->query('//*[@data-newsletter-bind="sub-header"]')->length;
    $requiredCount = $xpath->query('//*[@data-newsletter-bind="required-star" or @data-newsletter-bind="required-text"]')->length;
    $requiredText = $xpath->query('//*[@data-newsletter-bind="required-text"]')->item(0);
    if ($xpath->query('//*[@data-newsletter-preview]')->length !== 1
        || $xpath->query('//form|//input|//select|//script|//*[@data-newsletter-signup]')->length !== 0
        || $header->textContent !== (($attributes['header'] ?? '') ?: 'Newsletter Signup')
        || $submit->textContent !== (($attributes['submitText'] ?? '') ?: 'Subscribe')
        || !$submit->hasAttribute('disabled')
        || $submit->getAttribute('type') !== 'button'
        || $hasSubtitle !== (empty($attributes['subHeader']) ? 0 : 1)
        || $requiredCount !== (($attributes['showRequiredIndicator'] ?? true) ? 2 : 0)
        || ($requiredText && $requiredText->textContent !== ($attributes['requiredIndicatorText'] ?? '* = required field'))
        || str_contains($html, 'data-editor-')) {
        throw new RuntimeException('Newsletter preview must use the shared inert placeholder and preserve its text/visibility bindings.');
    }
}
echo "Newsletter PHP placeholder defaults, optional text, required indicators, Unicode and inertness checks passed.\n";
