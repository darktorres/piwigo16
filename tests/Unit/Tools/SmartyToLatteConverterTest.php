<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Piwigo\Tools\SmartyToLatte\Converter;

/**
 * Phase C foundation tests for the Smarty → Latte mechanical converter.
 * Each test pins one rewrite rule against an input/output pair derived
 * from a real Smarty site under `themes/`. The rules are intentionally
 * faithful — no `|noescape` is added on bare prints, even though Latte
 * auto-escapes by default; that's a security improvement and converted
 * templates need a hand-pass to mark legitimate raw-HTML prints.
 */
final class SmartyToLatteConverterTest extends TestCase
{
    private Converter $converter;

    #[\Override]
    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function test_foreach_with_key_and_item(): void
    {
        self::assertSame(
            '{foreach $tabsheet as $name => $sheet}',
            $this->converter->convert('{foreach from=$tabsheet key=name item=sheet}'),
        );
    }

    public function test_foreach_with_item_only(): void
    {
        self::assertSame(
            '{foreach $photos as $photo}',
            $this->converter->convert('{foreach from=$photos item=photo}'),
        );
    }

    public function test_smarty_dot_access_rewrites_to_array_index(): void
    {
        self::assertSame(
            "<a href=\"{\$sheet['url']}\">",
            $this->converter->convert('<a href="{$sheet.url}">'),
        );
    }

    public function test_smarty_dot_access_chained(): void
    {
        self::assertSame(
            "{\$arr['k1']['k2']}",
            $this->converter->convert('{$arr.k1.k2}'),
        );
    }

    public function test_smarty_dot_access_does_not_touch_text_outside_tags(): void
    {
        // Decimals in HTML text must not be touched — the rewriter only
        // walks {...} tag contents.
        self::assertSame(
            'price: $9.99',
            $this->converter->convert('price: $9.99'),
        );
    }

    public function test_if_not_keyword(): void
    {
        self::assertSame(
            '{if !$ENABLE_SYNCHRONIZATION}',
            $this->converter->convert('{if not $ENABLE_SYNCHRONIZATION}'),
        );
    }

    public function test_escape_filter_removed(): void
    {
        self::assertSame(
            '{$x}',
            $this->converter->convert('{$x|escape}'),
        );
    }

    public function test_escape_none_becomes_noescape(): void
    {
        self::assertSame(
            '{$x|noescape}',
            $this->converter->convert("{\$x|escape:'none'}"),
        );
    }

    public function test_combine_script_named_args(): void
    {
        self::assertSame(
            "{do combineScript(id: 'common', load: 'footer', path: 'themes/admin/_base/js/common.js')}",
            $this->converter->convert("{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}"),
        );
    }

    public function test_combine_css_named_args(): void
    {
        // Quote style is preserved — double-quoted Smarty input stays
        // double-quoted in the Latte output. Templates that prefer
        // single-quoted Latte source should be authored that way.
        self::assertSame(
            '{do combineCss(path: "themes/_base/print.css", order: -10)}',
            $this->converter->convert('{combine_css path="themes/_base/print.css" order=-10}'),
        );
    }

    public function test_define_derivative_with_type(): void
    {
        self::assertSame(
            "{var \$thumb = defineDerivative(type: 'thumb')}",
            $this->converter->convert("{define_derivative name='thumb' type='thumb'}"),
        );
    }

    public function test_define_derivative_with_dimensions(): void
    {
        self::assertSame(
            '{var $box = defineDerivative(width: 200, height: 200, crop: true)}',
            $this->converter->convert('{define_derivative name=\'box\' width=200 height=200 crop=true}'),
        );
    }

    public function test_include_renames_tpl_to_latte(): void
    {
        self::assertSame(
            "{include 'partials/header.latte'}",
            $this->converter->convert("{include file='partials/header.tpl'}"),
        );
    }

    public function test_printed_literal_filter_gets_print_prefix(): void
    {
        self::assertSame(
            "{='Help'|translate}",
            $this->converter->convert("{'Help'|translate}"),
        );
    }

    public function test_variable_print_does_not_get_extra_prefix(): void
    {
        // Latte handles `{$var|filter}` natively — must not add a stray `=`.
        self::assertSame(
            '{$x|translate}',
            $this->converter->convert('{$x|translate}'),
        );
    }

    public function test_assign_named_args(): void
    {
        self::assertSame(
            '{var $foo = $bar + 1}',
            $this->converter->convert('{assign var=foo value=$bar + 1}'),
        );
    }

    public function test_assign_quoted_var_name(): void
    {
        self::assertSame(
            "{var \$foo = 'literal'}",
            $this->converter->convert('{assign var="foo" value=\'literal\'}'),
        );
    }

    public function test_section_to_foreach(): void
    {
        $smarty = "{section name=i loop=\$photos}\n  <li>{\$photos[i]['name']}</li>\n{/section}";
        $latte = "{foreach \$photos as \$i => \$val}\n  <li>{\$photos[i]['name']}</li>\n{/foreach}";
        // The body still references $photos[i] — that residue is documented:
        // {section} body access via $photos[i] doesn't auto-translate;
        // hand-fix to {$val['name']} or whatever the body should be after.
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_capture_block_rewrites_name_and_smarty_capture(): void
    {
        $smarty = "{capture name=foo}line1\nline2{/capture}{\$smarty.capture.foo}";
        $latte = "{capture \$foo}line1\nline2{/capture}{\$foo}";
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_literal_block(): void
    {
        $smarty = "{literal}{ raw braces }{/literal}";
        $latte = "{syntax off}{ raw braces }{syntax on}";
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_html_head_block(): void
    {
        $smarty = "{html_head}<link rel=\"x\" href=\"y\">{/html_head}";
        $latte = '{capture $_pwgHead1}<link rel="x" href="y">{/capture}{do htmlHead($_pwgHead1)}';
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_html_head_block_unique_per_occurrence(): void
    {
        $smarty = "{html_head}A{/html_head}{html_head}B{/html_head}";
        $latte =
            '{capture $_pwgHead1}A{/capture}{do htmlHead($_pwgHead1)}'
            . '{capture $_pwgHead2}B{/capture}{do htmlHead($_pwgHead2)}';
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_html_style_block(): void
    {
        $smarty = "{html_style}.foo { color: red }{/html_style}";
        $latte = '{capture $_pwgStyle1}.foo { color: red }{/capture}{do htmlStyle($_pwgStyle1)}';
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_footer_script_block_no_args(): void
    {
        $smarty = "{footer_script}init();{/footer_script}";
        $latte = '{capture $_pwgFooter1}init();{/capture}{do footerScript($_pwgFooter1)}';
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_footer_script_block_with_require(): void
    {
        $smarty = "{footer_script require='common'}init();{/footer_script}";
        $latte = "{capture \$_pwgFooter1}init();{/capture}{do footerScript(\$_pwgFooter1, require: 'common')}";
        self::assertSame($latte, $this->converter->convert($smarty));
    }

    public function test_regex_replace_to_replace_re(): void
    {
        self::assertSame(
            "{\$s|replaceRe:'/foo/','bar'}",
            $this->converter->convert("{\$s|regex_replace:'/foo/':'bar'}"),
        );
    }

    public function test_cat_filter_single_arg(): void
    {
        self::assertSame(
            "{=\$x ~ 'foo'}",
            $this->converter->convert("{\$x|cat:'foo'}"),
        );
    }

    public function test_cat_filter_chained(): void
    {
        self::assertSame(
            "{=\$x ~ 'foo' ~ 'bar'}",
            $this->converter->convert("{\$x|cat:'foo':'bar'}"),
        );
    }

    public function test_default_filter_passes_through_unchanged(): void
    {
        // Latte has its own |default filter with the same semantics —
        // the converter must not touch it.
        self::assertSame(
            "{\$x|default:''}",
            $this->converter->convert("{\$x|default:''}"),
        );
    }

    public function test_smarty_comment_passthrough(): void
    {
        // Latte uses the same {*…*} comment syntax; the converter should
        // leave them untouched.
        self::assertSame(
            "{* a comment *}\n<h2>x</h2>",
            $this->converter->convert("{* a comment *}\n<h2>x</h2>"),
        );
    }

    /**
     * End-to-end on a real production template: tabsheet.tpl exercises
     * foreach (with key + item), smarty-style `and` keyword (which Latte
     * also accepts, so converter leaves it alone), conditional class
     * within an attribute, and dot-access on the foreach item.
     */
    public function test_tabsheet_tpl_round_trip(): void
    {
        $smarty = <<<'SMARTY'
{if isset($tabsheet) and count($tabsheet)}
<div id="tabsheet">
<ul class="tabsheet">
{foreach from=$tabsheet key=name item=sheet}
  <li class="{if ($name == $tabsheet_selected)}selected_tab{else}normal_tab{/if}">
    <a href="{$sheet.url}"><span>{$sheet.caption}</span></a>
  </li>
{/foreach}
</ul>
</div>
{/if}
SMARTY;

        $expected = <<<'LATTE'
{if isset($tabsheet) and count($tabsheet)}
<div id="tabsheet">
<ul class="tabsheet">
{foreach $tabsheet as $name => $sheet}
  <li class="{if ($name == $tabsheet_selected)}selected_tab{else}normal_tab{/if}">
    <a href="{$sheet['url']}"><span>{$sheet['caption']}</span></a>
  </li>
{/foreach}
</ul>
</div>
{/if}
LATTE;

        self::assertSame($expected, $this->converter->convert($smarty));
    }
}
