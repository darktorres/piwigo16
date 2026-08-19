<?php

declare(strict_types=1);

use Latte\Engine;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\SessionServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tools\PhpStan\Latte\LatteTemplateCompiler;
use Piwigo\Users\CurrentUser;

function latte_template_compiler_test_engine(): Engine
{
    $currentConfig = CurrentConfigTestFactory::get();
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    $engine = new Engine();
    $engine->addExtension(new PiwigoExtension(
        TemplateTestFactory::build(),
        LangTestFactory::get(),
        new AccessLevelChecker($currentUser, $currentConfig),
        SessionServiceTestFactory::get(),
        UrlServiceTestFactory::build(),
    ));

    return $engine;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-latte-compiler-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
    $this->repoRoot = dirname(__DIR__, 3);
    $this->outputDir = $root . '/analysis';
    $this->compiler = new LatteTemplateCompiler(
        latte_template_compiler_test_engine(),
        $this->repoRoot,
        $this->outputDir,
    );
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    if (is_dir($this->root)) {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
});

it('rewrites filter property-invokes to shim static calls', function (): void {
    $result = $this->compiler->compile(
        $this->repoRoot . '/themes/admin/default/template/comments.latte',
        [],
    );

    $code = (string) file_get_contents($result->outputPath);
    expect($code)
        ->toContain('\Piwigo\Tools\PhpStan\Latte\Generated\LatteAnalysisShims::translate(')
        ->not->toContain('$this->filters->')
        ->not->toContain('$this->global->fn');
});

it('strips the leading $this for non-Template-aware functions, keeping named args intact', function (): void {
    // A dedicated fixture, not a real template (P38's own inline-JS-extraction
    // campaign is steadily removing footerScript() from production templates,
    // so pinning this compiler-behavior test to a real template's content
    // would keep breaking as unrelated batches land) -- this only needs one
    // combineScript() call and one footerScript() call to exercise the
    // rewrite this test is actually about.
    $fixture = $this->root . '/footerscript-fixture.latte';
    file_put_contents($fixture, <<<'LATTE'
        {do combineScript(id: 'comments', load: 'footer', path: 'x.js')}
        {capture $tmpFooterScript}
        console.log('x');
        {/capture}
        {do footerScript($tmpFooterScript)}
        LATTE);

    $result = $this->compiler->compile($fixture, []);

    $code = (string) file_get_contents($result->outputPath);
    expect($code)
        ->toContain("LatteAnalysisShims::combineScript(id: 'comments'")
        ->toContain('LatteAnalysisShims::footerScript($tmpFooterScript)')
        ->not->toContain('combineScript($this');
});

it('keeps the leading $this for functions declared Template-aware', function (): void {
    $fixture = $this->root . '/footerscript-fixture.latte';
    file_put_contents($fixture, <<<'LATTE'
        {do combineScript(id: 'comments', load: 'footer', path: 'x.js')}
        {capture $tmpFooterScript}
        console.log('x');
        {/capture}
        {do footerScript($tmpFooterScript)}
        LATTE);

    $compiler = new LatteTemplateCompiler(
        latte_template_compiler_test_engine(),
        $this->repoRoot,
        $this->outputDir,
        templateAwareFunctions: ['footerScript'],
    );

    $result = $compiler->compile($fixture, []);

    expect((string) file_get_contents($result->outputPath))
        ->toContain('LatteAnalysisShims::footerScript($this, $tmpFooterScript)');
});

it('injects @var docblocks after every extract anchor, including block methods', function (): void {
    $template = $this->repoRoot . '/themes/admin/default/template/tags.latte';
    $result = $this->compiler->compile($template, [
        'tabsheet' => 'array<string, array{caption: string, url: string}>',
    ]);

    $code = (string) file_get_contents($result->outputPath);
    $anchors = preg_match_all('/^\s*extract\(/m', $code);
    expect($anchors)
        ->toBeGreaterThan(2);
    expect(preg_match_all('/@var array<string, array\{caption: string, url: string\}> \$tabsheet/', $code))
        ->toBe($anchors);
});

it('skips invalid PHP identifiers with a notice instead of emitting broken docblocks', function (): void {
    $result = $this->compiler->compile(
        $this->repoRoot . '/themes/admin/default/template/comments.latte',
        [
            'valid_name' => 'string',
            'invalid-name' => 'string',
        ],
    );

    $code = (string) file_get_contents($result->outputPath);
    expect($code)
        ->toContain('@var string $valid_name')
        ->not->toContain('invalid-name');
    expect($result->notices)
        ->toHaveCount(1);
});

it('is deterministic and write-only-if-changed for result-cache friendliness', function (): void {
    $template = $this->repoRoot . '/themes/admin/default/template/comments.latte';

    $first = $this->compiler->compile($template, [
        'CSRF_TOKEN' => 'string',
    ]);
    $mtime = filemtime($first->outputPath);
    $second = $this->compiler->compile($template, [
        'CSRF_TOKEN' => 'string',
    ]);

    expect($first->changed)
        ->toBeTrue()
        ->and($second->changed)
        ->toBeFalse()
        ->and($second->outputPath)
        ->toBe($first->outputPath)
        ->and(filemtime($second->outputPath))
        ->toBe($mtime);
});

it('hard-fails on a filter/function calling convention the rewrite does not cover', function (): void {
    $engine = new class() extends Engine {
        public function compile(string $name): string
        {
            return "<?php\necho (\$this->filters->somefilter)('x');\n\$ʟ_fi = new LR\\FilterInfo('html');\necho \$this->filters->filterContent('other', \$ʟ_fi, 'y');\n";
        }
    };
    $compiler = new LatteTemplateCompiler($engine, $this->repoRoot, $this->outputDir);

    $compiler->compile('ignored.latte', []);
})->throws(LogicException::class, 'Unrewritten');
