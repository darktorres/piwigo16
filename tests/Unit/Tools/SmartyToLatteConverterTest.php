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
    private ?Converter $converter = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    private function converter(): Converter
    {
        return $this->converter ?? throw new \LogicException('setUp not called');
    }

    public function test_foreach_with_key_and_item(): void
    {
        self::assertSame(
            '{foreach $tabsheet as $name => $sheet}',
            $this->converter()->convert('{foreach from=$tabsheet key=name item=sheet}'),
        );
    }

    public function test_foreach_with_item_only(): void
    {
        self::assertSame(
            '{foreach $photos as $photo}',
            $this->converter()->convert('{foreach from=$photos item=photo}'),
        );
    }

    public function test_smarty_dot_access_rewrites_to_array_index(): void
    {
        self::assertSame(
            "<a href=\"{\$sheet['url']}\">",
            $this->converter()->convert('<a href="{$sheet.url}">'),
        );
    }

    public function test_smarty_dot_access_chained(): void
    {
        self::assertSame(
            "{\$arr['k1']['k2']}",
            $this->converter()->convert('{$arr.k1.k2}'),
        );
    }

    public function test_smarty_dot_access_does_not_touch_text_outside_tags(): void
    {
        // Decimals in HTML text must not be touched — the rewriter only
        // walks {...} tag contents.
        self::assertSame(
            'price: $9.99',
            $this->converter()->convert('price: $9.99'),
        );
    }

    public function test_if_not_keyword(): void
    {
        self::assertSame(
            '{if !$ENABLE_SYNCHRONIZATION}',
            $this->converter()->convert('{if not $ENABLE_SYNCHRONIZATION}'),
        );
    }

    public function test_escape_filter_removed(): void
    {
        self::assertSame(
            '{$x}',
            $this->converter()->convert('{$x|escape}'),
        );
    }

    public function test_escape_none_becomes_noescape(): void
    {
        self::assertSame(
            '{$x|noescape}',
            $this->converter()->convert("{\$x|escape:'none'}"),
        );
    }

    public function test_combine_script_named_args(): void
    {
        self::assertSame(
            "{do combineScript(id: 'common', load: 'footer', path: 'themes/admin/_base/js/common.js')}",
            $this->converter()->convert("{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}"),
        );
    }

    public function test_combine_css_named_args(): void
    {
        // Quote style is preserved — double-quoted Smarty input stays
        // double-quoted in the Latte output. Templates that prefer
        // single-quoted Latte source should be authored that way.
        self::assertSame(
            '{do combineCss(path: "themes/_base/print.css", order: -10)}',
            $this->converter()->convert('{combine_css path="themes/_base/print.css" order=-10}'),
        );
    }

    public function test_define_derivative_with_type(): void
    {
        self::assertSame(
            "{var \$thumb = defineDerivative(type: 'thumb')}",
            $this->converter()->convert("{define_derivative name='thumb' type='thumb'}"),
        );
    }

    public function test_define_derivative_with_dimensions(): void
    {
        self::assertSame(
            '{var $box = defineDerivative(width: 200, height: 200, crop: true)}',
            $this->converter()->convert('{define_derivative name=\'box\' width=200 height=200 crop=true}'),
        );
    }

    public function test_include_renames_tpl_to_latte(): void
    {
        self::assertSame(
            "{include 'partials/header.latte'}",
            $this->converter()->convert("{include file='partials/header.tpl'}"),
        );
    }

    public function test_printed_literal_filter_gets_print_prefix(): void
    {
        self::assertSame(
            "{='Help'|translate}",
            $this->converter()->convert("{'Help'|translate}"),
        );
    }

    public function test_variable_print_does_not_get_extra_prefix(): void
    {
        // Latte handles `{$var|filter}` natively — must not add a stray `=`.
        self::assertSame(
            '{$x|translate}',
            $this->converter()->convert('{$x|translate}'),
        );
    }

    public function test_assign_named_args(): void
    {
        self::assertSame(
            '{var $foo = $bar + 1}',
            $this->converter()->convert('{assign var=foo value=$bar + 1}'),
        );
    }

    public function test_assign_quoted_var_name(): void
    {
        self::assertSame(
            "{var \$foo = 'literal'}",
            $this->converter()->convert('{assign var="foo" value=\'literal\'}'),
        );
    }

    public function test_section_to_foreach(): void
    {
        $smarty = "{section name=i loop=\$photos}\n  <li>{\$photos[i]['name']}</li>\n{/section}";
        $latte = "{foreach \$photos as \$i => \$val}\n  <li>{\$photos[i]['name']}</li>\n{/foreach}";
        // The body still references $photos[i] — that residue is documented:
        // {section} body access via $photos[i] doesn't auto-translate;
        // hand-fix to {$val['name']} or whatever the body should be after.
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_capture_block_rewrites_name_and_smarty_capture(): void
    {
        $smarty = "{capture name=foo}line1\nline2{/capture}{\$smarty.capture.foo}";
        $latte = "{capture \$foo}line1\nline2{/capture}{\$foo}";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_literal_block(): void
    {
        $smarty = "{literal}{ raw braces }{/literal}";
        $latte = "{syntax off}{ raw braces }{syntax on}";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_html_head_block(): void
    {
        $smarty = "{html_head}<link rel=\"x\" href=\"y\">{/html_head}";
        $latte = '{capture $_pwgHead1}<link rel="x" href="y">{/capture}{do htmlHead($_pwgHead1)}';
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_html_head_block_unique_per_occurrence(): void
    {
        $smarty = "{html_head}A{/html_head}{html_head}B{/html_head}";
        $latte =
            '{capture $_pwgHead1}A{/capture}{do htmlHead($_pwgHead1)}'
            . '{capture $_pwgHead2}B{/capture}{do htmlHead($_pwgHead2)}';
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_html_style_block(): void
    {
        $smarty = "{html_style}.foo { color: red }{/html_style}";
        $latte = '{capture $_pwgStyle1}.foo { color: red }{/capture}{do htmlStyle($_pwgStyle1)}';
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_footer_script_block_no_args(): void
    {
        $smarty = "{footer_script}init();{/footer_script}";
        $latte = '{capture $_pwgFooter1}init();{/capture}{do footerScript($_pwgFooter1)}';
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_footer_script_block_with_require(): void
    {
        $smarty = "{footer_script require='common'}init();{/footer_script}";
        $latte = "{capture \$_pwgFooter1}init();{/capture}{do footerScript(\$_pwgFooter1, require: 'common')}";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_regex_replace_to_replace_re(): void
    {
        self::assertSame(
            "{\$s|replaceRe:'/foo/','bar'}",
            $this->converter()->convert("{\$s|regex_replace:'/foo/':'bar'}"),
        );
    }

    public function test_cat_filter_passes_through_via_runtime(): void
    {
        // |cat is registered in PiwigoExtension (multi-arg, returns
        // string concat), so the converter doesn't need to rewrite it
        // syntactically — the multi-arg-pipe rule converts the colons
        // to commas and the runtime filter takes over.
        self::assertSame(
            "{\$x|cat:'foo'}",
            $this->converter()->convert("{\$x|cat:'foo'}"),
        );
        self::assertSame(
            "{\$x|cat:'foo','bar'}",
            $this->converter()->convert("{\$x|cat:'foo':'bar'}"),
        );
    }

    public function test_default_filter_passes_through_unchanged(): void
    {
        // Latte has its own |default filter with the same semantics —
        // the converter must not touch it.
        self::assertSame(
            "{\$x|default:''}",
            $this->converter()->convert("{\$x|default:''}"),
        );
    }

    public function test_if_not_with_function_call(): void
    {
        // {if not empty($x)} — `not` followed by a function call, not
        // a bare variable. The earlier-iteration regex required a
        // following `$`, so this pattern survived until the
        // generalization landed.
        self::assertSame(
            '{if !empty($remote_output)}',
            $this->converter()->convert('{if not empty($remote_output)}'),
        );
    }

    public function test_if_not_with_paren_expression(): void
    {
        self::assertSame(
            '{if !($x === 0)}',
            $this->converter()->convert('{if not ($x === 0)}'),
        );
    }

    public function test_operator_keywords_eq_neq_gt_lt(): void
    {
        self::assertSame(
            '{if $pending_failed > 0}',
            $this->converter()->convert('{if $pending_failed gt 0}'),
        );
        self::assertSame(
            '{if $a == $b}',
            $this->converter()->convert('{if $a eq $b}'),
        );
        self::assertSame(
            '{if $a != $b}',
            $this->converter()->convert('{if $a neq $b}'),
        );
        self::assertSame(
            '{if $count >= 3}',
            $this->converter()->convert('{if $count gte 3}'),
        );
        self::assertSame(
            '{if $count <= 3}',
            $this->converter()->convert('{if $count lte 3}'),
        );
    }

    public function test_operator_keywords_only_inside_if_tags(): void
    {
        // The `eq` substring appearing in a regular HTML attribute or
        // text must not be touched.
        self::assertSame(
            '<a class="eq-button">eq</a>',
            $this->converter()->convert('<a class="eq-button">eq</a>'),
        );
    }

    public function test_else_if_with_space(): void
    {
        self::assertSame(
            "{elseif \$x == 'foo'}",
            $this->converter()->convert("{else if \$x == 'foo'}"),
        );
    }

    public function test_function_definition_to_define(): void
    {
        $smarty = "{function name=tagContent}\n  body\n{/function}";
        $latte = "{define tagContent}\n  body\n{/define}";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_get_combined_css_to_function_call(): void
    {
        self::assertSame(
            '{=getCombinedCss()}',
            $this->converter()->convert('{get_combined_css}'),
        );
    }

    public function test_multi_arg_pipe_filter_colon_to_comma(): void
    {
        // Smarty: |translate:$a:$b → Latte: |translate:$a,$b
        self::assertSame(
            "{='msg'|translate:\$a,\$b}",
            $this->converter()->convert("{'msg'|translate:\$a:\$b}"),
        );
    }

    public function test_multi_arg_pipe_filter_three_args(): void
    {
        self::assertSame(
            "{='%s and %s and %s'|translate:\$a,\$b,\$c}",
            $this->converter()->convert("{'%s and %s and %s'|translate:\$a:\$b:\$c}"),
        );
    }

    public function test_multi_arg_pipe_filter_preserves_colon_inside_strings(): void
    {
        // The `time:30` literal contains a colon that must NOT be
        // rewritten to a comma. Variable print at the head doesn't
        // need the `=` prefix Latte requires for printed string
        // literals.
        self::assertSame(
            "{\$s|replaceRe:'/foo/','time:30'}",
            $this->converter()->convert("{\$s|regex_replace:'/foo/':'time:30'}"),
        );
    }

    public function test_foreach_with_name_arg_drops_it(): void
    {
        // {foreach from=$arr item=v name=loop} → {foreach $arr as $v}
        // The `name=loop` is dropped; references to
        // $smarty.foreach.loop.* in the body are residue and need
        // hand-rewrite to Latte's $iterator.
        self::assertSame(
            '{foreach $arr as $v}',
            $this->converter()->convert('{foreach from=$arr item=v name=loop}'),
        );
    }

    public function test_foreach_with_key_item_name_arg(): void
    {
        self::assertSame(
            '{foreach $arr as $k => $v}',
            $this->converter()->convert('{foreach from=$arr key=k item=v name=loop}'),
        );
    }

    public function test_assign_positional_form(): void
    {
        // {assign 'foo' ''} (positional, two-arg form)
        self::assertSame(
            "{var \$foo = ''}",
            $this->converter()->convert("{assign 'foo' ''}"),
        );
    }

    public function test_assign_with_pipe_filter_wraps_in_parens(): void
    {
        // Latte's {var} rejects a bare pipe; the converter wraps the
        // RHS in parens to keep the expression unambiguous.
        self::assertSame(
            '{var $isSelected = ($id|in_array:$selection)}',
            $this->converter()->convert('{assign var=isSelected value=$id|in_array:$selection}'),
        );
    }

    public function test_function_definition_positional(): void
    {
        // {function tagContent} (no `name=`)
        self::assertSame(
            "{define tagContent}\nbody\n{/define}",
            $this->converter()->convert("{function tagContent}\nbody\n{/function}"),
        );
    }

    public function test_get_combined_scripts_with_args(): void
    {
        self::assertSame(
            "{=getCombinedScripts(load: 'footer')}",
            $this->converter()->convert("{get_combined_scripts load='footer'}"),
        );
    }

    public function test_get_combined_scripts_no_args(): void
    {
        self::assertSame(
            '{=getCombinedScripts()}',
            $this->converter()->convert('{get_combined_scripts}'),
        );
    }

    public function test_strip_to_spaceless(): void
    {
        $smarty = "{strip}\n  <li>x</li>\n{/strip}";
        $latte = "{spaceless}\n  <li>x</li>\n{/spaceless}";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_escape_with_argument_keyword(): void
    {
        // |escape:html (unquoted) and |escape:'html' (quoted) both
        // collapse to bare print under Latte's auto-escape default.
        self::assertSame(
            '{$x|json_encode}',
            $this->converter()->convert('{$x|json_encode|escape:html}'),
        );
        self::assertSame(
            '{$x|json_encode}',
            $this->converter()->convert("{\$x|json_encode|escape:'html'}"),
        );
    }

    public function test_smarty_comment_passthrough(): void
    {
        // Latte uses the same {*…*} comment syntax; the converter should
        // leave them untouched.
        self::assertSame(
            "{* a comment *}\n<h2>x</h2>",
            $this->converter()->convert("{* a comment *}\n<h2>x</h2>"),
        );
    }

    public function test_html_options_with_options_and_selected(): void
    {
        self::assertSame(
            '{=htmlOptions(options: $level_options, selected: $level_selected)|noescape}',
            $this->converter()->convert('{html_options options=$level_options selected=$level_selected}'),
        );
    }

    public function test_html_options_with_name_values_output_selected(): void
    {
        // `name='url[]'` keeps the bracket syntax through the parser; the
        // converter quotes the original literal verbatim. Smarty's
        // `output=$tpl.url_parameter` becomes `$tpl['url_parameter']`
        // after the dot-access pass.
        self::assertSame(
            "{=htmlOptions(name: 'url[]', output: \$tpl['url_parameter'], values: \$tpl['url_parameter'], selected: \$tpl['selected_url'])|noescape}",
            $this->converter()->convert("{html_options name='url[]' output=\$tpl.url_parameter values=\$tpl.url_parameter selected=\$tpl.selected_url}"),
        );
    }

    public function test_html_radios(): void
    {
        self::assertSame(
            "{=htmlRadios(name: 'expand', options: \$radio_options, selected: \$GUEST_EXPAND)|noescape}",
            $this->converter()->convert("{html_radios name='expand' options=\$radio_options selected=\$GUEST_EXPAND}"),
        );
    }

    public function test_math(): void
    {
        self::assertSame(
            '{=math(equation: "abs(pos)", pos: $block[\'pos\'])}',
            $this->converter()->convert('{math equation="abs(pos)" pos=$block.pos}'),
        );
    }

    public function test_counter_dropped(): void
    {
        // `{counter}` is dead in Piwigo's templates (assigned but never
        // read); the converter strips both the tag and the trailing
        // newline so the surrounding markup stays compact.
        self::assertSame(
            "<p>before</p>\n<p>after</p>",
            $this->converter()->convert("<p>before</p>\n{counter assign=i}\n<p>after</p>"),
        );
    }

    public function test_user_defined_function_call_after_define(): void
    {
        // `{function tagContent}…{/function}` becomes `{define}` (existing
        // pass); subsequent calls become `{include tagContent, …}` with
        // PHP-style named args (Latte's call syntax for `{define}`-style
        // blocks).
        $smarty = "{function tagContent}body {\$tag_name}{/function}\nstart\n{tagContent tag_name='hello'}\nend";
        $latte = "{define tagContent}body {\$tag_name}{/define}\nstart\n{include tagContent, tag_name: 'hello'}\nend";
        self::assertSame($latte, $this->converter()->convert($smarty));
    }

    public function test_embedded_print_in_if(): void
    {
        // `{if {$X}}` is Smarty 5 sugar; Latte rejects the inner `{`.
        // `{if "first" == {$Y}}` is the same shape with a comparison.
        self::assertSame('{if $U_SHOW_TEMPLATE_TAB}', $this->converter()->convert('{if {$U_SHOW_TEMPLATE_TAB}}'));
        self::assertSame(
            '{if "first" == $POS_PREF}',
            $this->converter()->convert('{if "first" == {$POS_PREF}}'),
        );
    }

    public function test_smarty_dot_access_with_variable_index(): void
    {
        // `$arr.$key` (variable as the index) → `$arr[$key]`. The literal
        // dot-access path `$arr.foo` is covered separately by the
        // tabsheet round-trip test.
        self::assertSame(
            '{if !isset($ferrors[$type])}',
            $this->converter()->convert('{if !isset($ferrors.$type)}'),
        );
    }

    public function test_pipe_filter_in_if_to_function_call(): void
    {
        // `|count` (no arg) inside `{if}` → `count($x)` because Latte
        // rejects pipe filters inside `{if}` expressions.
        self::assertSame(
            '{if count($related_categories) < 1}',
            $this->converter()->convert('{if $related_categories|count < 1}'),
        );
        // Same with chained dot/bracket access — the dot-access pass
        // runs first so the pipe rewrite sees the canonical bracket form.
        self::assertSame(
            "{if count(\$element['related_categories']) < 1}",
            $this->converter()->convert('{if $element.related_categories|count < 1}'),
        );
    }

    public function test_backtick_string_interpolation(): void
    {
        // Smarty's backtick var-in-string only fires inside tag bodies,
        // not in surrounding HTML text. Lands as PHP `.` concat because
        // Latte's `~` concat is rejected inside function-call args.
        self::assertSame(
            '{do combineCss(path: "themes/admin/" . $theme[\'id\'] . "/theme.css")}',
            $this->converter()->convert('{do combineCss(path: "themes/admin/`$theme[\'id\']`/theme.css")}'),
        );
    }

    public function test_capture_with_assign_keyword(): void
    {
        // Smarty's `{capture}` accepted both `name=` and `assign=` to
        // bind the body to a template variable.
        self::assertSame(
            '{capture $rate_over}body{/capture}',
            $this->converter()->convert('{capture assign=rate_over}body{/capture}'),
        );
    }

    public function test_if_break_idiom_to_break_if(): void
    {
        // Latte rejects bare `{break}` in foreach scope; the idiomatic
        // shape is `{breakIf <expr>}`. The whole `{if X}{break}{/if}`
        // block collapses to a single tag.
        self::assertSame(
            '{breakIf ($iterator->getCounter() - 1) > 29}',
            $this->converter()->convert('{if $rate_arr@index > 29}{break}{/if}'),
        );
    }

    public function test_iterator_attribute_smarty5_syntax(): void
    {
        // Smarty 5 added `$item@index` etc. as the per-element shortcut
        // for `$smarty.foreach.NAME.index`. Both shapes map to Latte's
        // implicit `$iterator` API.
        self::assertSame(
            '{if ($iterator->getCounter() - 1) > 29}',
            $this->converter()->convert('{if $rate_arr@index > 29}'),
        );
        self::assertSame(
            '{$iterator->getCounter()}',
            $this->converter()->convert('{$rate_arr@iteration}'),
        );
    }

    public function test_filter_arg_brace_unwrap(): void
    {
        // Smarty allowed `{round(...)}` as a sub-print inside a filter
        // arg; Latte rejects the inner `{`. The converter strips the
        // wrapper while leaving non-filter braces alone, and the
        // resulting literal-prefix print picks up the `{=...}` print
        // marker from `rewritePrintedLiteralFilter`.
        self::assertSame(
            '{="%s MB"|translate:round($cache_sizes[1][\'value\'][$url] / 1024 / 1024, 2)}',
            $this->converter()->convert('{"%s MB"|translate:{round($cache_sizes[1][\'value\'][$url] / 1024 / 1024, 2)}}'),
        );
    }

    public function test_smarty_dot_access_after_php_property_chain(): void
    {
        // Smarty allows mixed `$obj->prop.key` with property + dot. Without
        // chain support the dot-access rule leaves `.key` for Latte to parse
        // as concat with a constant — producing an unqualified-constant lint
        // warning (e.g. `U_CATEGORIES`). The leading-expr regex must walk
        // through `->prop` segments before the dotted suffix.
        self::assertSame(
            "{\$block->data['U_CATEGORIES']}",
            $this->converter()->convert('{$block->data.U_CATEGORIES}'),
        );
        // Variable index after a property chain.
        self::assertSame(
            '{if isset($block->data[$key])}',
            $this->converter()->convert('{if isset($block->data.$key)}'),
        );
    }

    public function test_combine_css_path_with_nested_print(): void
    {
        // Path values that interpolate a Smarty var (e.g. theme color)
        // contain `{...}` inside the outer combine_css `{...}` tag. The
        // rule's regex must allow one level of nested braces, otherwise it
        // truncates after the first inner `}` and produces broken output
        // like `path: "…{$x['k'])}-…"`.
        self::assertSame(
            '{do combineCss(path: "themes/_base/css/{$themeconf[\'colorscheme\']}-search.css", order: -100)}',
            $this->converter()->convert(
                '{combine_css path="themes/_base/css/{$themeconf.colorscheme}-search.css" order=-100}'
            ),
        );
    }

    public function test_smarty_foreach_iterator_bracket_form_after_dot_access(): void
    {
        // The dot-access pass runs before the iterator rewrite, so by the
        // time the latter sees the input the dotted shape `$smarty.foreach.X.first`
        // has been promoted to bracket access. The rule must match the
        // bracket form too — otherwise `$smarty['foreach']['X']['first']`
        // leaks into the output and fails at render with "Undefined
        // variable $smarty".
        self::assertSame(
            '{if !$iterator->isFirst()}',
            $this->converter()->convert("{if !\$smarty.foreach.tag_loop.first}"),
        );
        // Outer parens come from the source; inner from the rewrite — the
        // double-wrapping is harmless under PHP/Latte.
        self::assertSame(
            '{if (($iterator->getCounter() - 1)) % 2 != 0}',
            $this->converter()->convert("{if (\$smarty.foreach.cat_loop.index) % 2 != 0}"),
        );
    }

    public function test_smarty_other_residues_rewritten_to_php_equivalents(): void
    {
        // `$smarty.now` is the current Unix timestamp.
        self::assertSame(
            '{=time()|date_format:"%d"}',
            $this->converter()->convert('{$smarty.now|date_format:"%d"}'),
        );
        // `$smarty.server.X` → PHP superglobal.
        self::assertSame(
            "{=(\$_SERVER['REQUEST_URI'] ?? '')|urlencode}",
            $this->converter()->convert('{$smarty.server.REQUEST_URI|urlencode}'),
        );
        // `$smarty.cookies.X` → `$_COOKIE['X']` (no `?? null` so
        // surrounding `isset(...)` keeps working).
        self::assertSame(
            "{if \$_COOKIE['pwg_album_manager_view'] == 'compact'}",
            $this->converter()->convert("{if \$smarty.cookies.pwg_album_manager_view == 'compact'}"),
        );
        self::assertSame(
            "{if !isset(\$_COOKIE['pwg_tags_per_page'])}",
            $this->converter()->convert("{if !isset(\$smarty.cookies.pwg_tags_per_page)}"),
        );
        // `$smarty.capture.NAME` → `$NAME`. Latte's {capture $name}
        // binds the result to a normal variable.
        self::assertSame(
            '{if !$inc_album_selector}',
            $this->converter()->convert('{if !$smarty.capture.inc_album_selector}'),
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

        self::assertSame($expected, $this->converter()->convert($smarty));
    }
}
