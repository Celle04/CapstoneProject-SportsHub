<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A printed report is a document of the school, not a picture of the portal.
 * The print stylesheet therefore has to take the application chrome out of
 * the page: the navigation, the top bar with the install button, the theme
 * switch and the notification bell, and the panels that only exist on the
 * screen.
 *
 * The block has to sit at the end of the stylesheet as well: several rules
 * further down set the font size and the padding of the tables and the
 * cards, and the rule that comes last is the one that counts.
 */
class PrintStylesheetTest extends TestCase
{
    public function test_the_print_stylesheet_hides_the_portal_chrome(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $start = strpos($css, '@media print');
        $this->assertNotFalse($start, 'the print stylesheet is missing');

        $block = substr($css, $start, (int) strpos($css, "\n}", $start) - $start);

        foreach ([
            '.portal-sidebar',
            '.portal-topbar',
            '.module-header',
            '.report-filter-panel',
            '.report-actions',
            '.report-export-panel',
            '.report-export-actions',
            '.report-export-sports',
        ] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $block,
                'the print stylesheet no longer hides '.$selector
            );
        }

        $this->assertStringContainsString('display: none !important', $block);
    }

    public function test_the_print_stylesheet_gives_the_table_the_whole_page(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $start = strpos($css, '@media print');
        $block = substr($css, $start, (int) strpos($css, "\n}", $start) - $start);

        $this->assertStringContainsString('width: 100% !important', $block);
        $this->assertStringContainsString('min-width: 0 !important', $block);
        $this->assertStringContainsString('display: table-header-group', $block);
        $this->assertStringContainsString('break-inside: avoid', $block);
    }

    public function test_the_print_block_stands_at_the_end_of_the_stylesheet(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $print = strrpos($css, '@media print');
        $last = strrpos($css, '@media');

        $this->assertSame($last, $print, 'the print block is not the last block of the stylesheet');
    }

    public function test_the_report_keeps_its_own_print_header(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $this->assertStringContainsString('.report-print-header { display: block;', $css);
    }
}
