<?php

namespace IlBronza\StaticSite\Tests\Feature;

use IlBronza\StaticSite\Builder;
use IlBronza\StaticSite\Contracts\PageSource;
use IlBronza\StaticSite\ExportContext;
use IlBronza\StaticSite\Http\Middleware\Exportable;
use IlBronza\StaticSite\Manifest;
use IlBronza\StaticSite\Page;
use IlBronza\StaticSite\StaticSiteServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class Articles implements PageSource
{
    public function routes(): array
    {
        return ['articles.show', 'articles.index'];
    }

    public function pages(): iterable
    {
        yield new Page('articles.show', ['slug' => 'one']);
        yield new Page('articles.index');
        yield new Page('articles.index', query: ['page' => 2], path: '/articles/page/2');
    }
}

final class BuildTest extends TestCase
{
    private string $directory;

    public static function applicationBasePath(): string
    {
        return __DIR__.'/../runtime';
    }

    protected function getPackageProviders($app): array
    {
        return [StaticSiteServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://backend.example');
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware(Exportable::class)->group(function () {
            Route::get('/', fn () => response('<!doctype html><html><head><link rel="stylesheet" href="/assets/site.css"></head><body><a href="/articles/one">One</a><a href="/articles?page=2&amp;sort=name">Next</a><a href="/articles?page=1&amp;sort=name">First</a><img src="/media/public.svg"><p>'.(auth()->check() ? 'ADMIN' : 'GUEST').'</p></body></html>'))->name('home');
            Route::get('/articles', fn () => response('<html><body>Page '.request('page', 1).'<a href="/">Home</a></body></html>'))->name('articles.index');
            Route::get('/articles/{slug}', fn ($slug) => response('<html><body>'.$slug.'<a href="https://www.example">Home</a></body></html>'))->name('articles.show');
            Route::get('/old', fn () => redirect('/articles/one', 301))->name('old');
        });
        Route::get('/admin', fn () => response('PRIVATE'))->name('admin');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/static-site-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/assets', 0777, true);
        mkdir($this->directory.'/media', 0777, true);
        file_put_contents($this->directory.'/assets/site.css', 'body{background:url("../media/public.svg")}');
        file_put_contents($this->directory.'/assets/unsafe.php', '<?php echo "PRIVATE";');
        file_put_contents($this->directory.'/media/public.svg', '<svg/>');
        file_put_contents($this->directory.'/media/draft.svg', '<svg>SECRET</svg>');
        config()->set('static-site.sources', [Articles::class]);
        config()->set('static-site.query_defaults', ['articles.index' => ['sort' => 'name', 'page' => 1]]);
        config()->set('static-site.assets', [
            ['source' => $this->directory.'/assets', 'destination' => 'assets', 'copy' => 'all'],
            ['source' => $this->directory.'/media', 'destination' => 'media'],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function build(): array
    {
        return app(Builder::class)->build($this->directory.'/export', 'https://www.example');
    }

    public function test_complete_guest_export_copies_only_public_dependencies_and_preserves_backend_state(): void
    {
        auth()->guard()->setUser(new GenericUser(['id' => 1]));
        $originalRequest = request();
        $report = $this->build();
        $output = $report['output'];
        $this->assertSame(5, $report['pages']);
        $this->assertFileExists($output.'/articles/one/index.html');
        $this->assertFileExists($output.'/articles/page/2/index.html');
        $home = file_get_contents($output.'/index.html');
        $this->assertStringContainsString('href="/articles/page/2/"', $home);
        $this->assertStringContainsString('href="/articles/"', $home);
        $this->assertStringContainsString('href="/"', file_get_contents($output.'/articles/one/index.html'));
        $this->assertStringContainsString('GUEST', $home);
        $this->assertStringNotContainsString('ADMIN', $home);
        $this->assertFileExists($output.'/media/public.svg');
        $this->assertFileDoesNotExist($output.'/media/draft.svg');
        $this->assertFileDoesNotExist($output.'/assets/unsafe.php');
        $this->assertFileDoesNotExist($output.'/admin/index.html');
        $this->assertStringContainsString('url("/media/public.svg")', file_get_contents($output.'/assets/site.css'));
        $this->assertStringContainsString('/old /articles/one/ 301', file_get_contents($output.'/_redirects'));
        $this->assertStringNotContainsString('/old/', file_get_contents($output.'/sitemap.xml'));
        $this->assertSame($originalRequest, request());
        $this->assertTrue(auth()->check());
        $this->assertSame('https://backend.example', config('app.url'));
        $this->assertFalse(app(ExportContext::class)->building);
    }

    public function test_missing_parameter_source_is_an_error_even_if_no_links_point_at_it(): void
    {
        config()->set('static-site.sources', []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing page source');
        app(Manifest::class)->collect();
    }

    public function test_failed_build_keeps_previous_export_and_resets_context(): void
    {
        $this->build();
        $original = file_get_contents($this->directory.'/export/index.html');
        Route::get('/broken', fn () => response('<a href="/not-exported">Broken</a>'))->middleware(Exportable::class)->name('broken');
        try {
            $this->build();
            $this->fail('An unexported link must fail the build.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unexported local URL', $exception->getMessage());
        }
        $this->assertSame($original, file_get_contents($this->directory.'/export/index.html'));
        $this->assertFalse(app(ExportContext::class)->building);
        $this->assertSame([], glob($this->directory.'/export.build-*'));
    }

    public function test_unmanaged_output_is_never_replaced(): void
    {
        mkdir($this->directory.'/export');
        file_put_contents($this->directory.'/export/important.txt', 'keep');
        try {
            $this->build();
            $this->fail('Must reject an unmanaged output.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not owned', $exception->getMessage());
        }
        $this->assertSame('keep', file_get_contents($this->directory.'/export/important.txt'));
    }

    public function test_post_forms_cannot_enter_the_static_export(): void
    {
        Route::get('/form', fn () => response('<form method="post" action="/"></form>'))->middleware(Exportable::class)->name('form');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('server form');
        $this->build();
    }

    public function test_output_collisions_are_rejected(): void
    {
        config()->set('static-site.sources', [Articles::class, CollidingArticles::class]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('same output');
        app(Manifest::class)->collect();
    }

    public function test_asset_symlinks_cannot_export_files_outside_the_registered_root(): void
    {
        file_put_contents($this->directory.'/private.svg', '<svg>PRIVATE</svg>');
        symlink($this->directory.'/private.svg', $this->directory.'/media/escape.svg');
        Route::get('/escape', fn () => response('<img src="/media/escape.svg">'))->middleware(Exportable::class)->name('escape');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexported local URL');
        $this->build();
    }

    public function test_successful_rebuild_removes_pages_that_are_no_longer_exportable(): void
    {
        Route::get('/removed', fn () => response('<p>Old page</p>'))->middleware(Exportable::class)->name('removed');
        $this->build();
        $this->assertFileExists($this->directory.'/export/removed/index.html');
        config()->set('static-site.exclude', ['removed']);
        $this->build();
        $this->assertFileDoesNotExist($this->directory.'/export/removed/index.html');
    }

    public function test_remote_resources_require_a_local_copy(): void
    {
        Route::get('/remote', fn () => response('<script src="https://cdn.example/app.js"></script>'))->middleware(Exportable::class)->name('remote');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not self-contained');
        $this->build();
    }

    public function test_json_urls_are_rewritten_without_treating_plain_labels_as_paths(): void
    {
        Route::get('/json', fn () => response('<script type="application/json">{"url":"https://www.example/articles/one","label":"/not-a-url","empty":{}}</script>'))
            ->middleware(Exportable::class)->name('json');
        $this->build();
        $html = file_get_contents($this->directory.'/export/json/index.html');
        $this->assertStringContainsString('"url":"\\/articles\\/one\\/"', $html);
        $this->assertStringContainsString('"label":"\\/not-a-url"', $html);
        $this->assertStringContainsString('"empty":{}', $html);
    }

    public function test_output_cannot_be_nested_inside_a_copied_asset_root(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside an asset directory');
        app(Builder::class)->build($this->directory.'/assets/export', 'https://www.example');
    }
}

class CollidingArticles implements PageSource
{
    public function routes(): array
    {
        return ['articles.show'];
    }

    public function pages(): iterable
    {
        yield new Page('articles.show', ['slug' => 'two'], path: '/articles/one');
    }
}
