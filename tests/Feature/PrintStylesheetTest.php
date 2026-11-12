<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A printed report is a document of the school, not a picture of the portal.
 * The print stylesheet therefore has to take the application chrome out of
 * the page: the navigation, the top bar with the install button, the theme
 * switch and the notification bell, and the filter panels.
 */
class PrintStylesheetTest extends TestCase
{
    public function test_the_print_stylesheet_hides_the_portal_chrome(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $start = strpos($css, '@media print');
        $this->assertNotFalse($start, 'the print stylesheet is missing');

        $block = substr($css, $start, (int) strpos($css, "\n}", $start) - $start);

        foreach (['.portal-sidebar', '.portal-topbar', '.module-header', '.report-filter-panel', '.report-actions'] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $block,
                'the print stylesheet no longer hides '.$selector
            );
        }

        $this->assertStringContainsString('display: none !important', $block);
    }

    public function test_the_report_keeps_its_own_print_header(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $this->assertStringContainsString('.report-print-header { display: block;', $css);
    }
}
