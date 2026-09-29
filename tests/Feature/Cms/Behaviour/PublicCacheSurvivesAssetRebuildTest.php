<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * A rebuilt front end must not leave the cached site pointing at assets that no longer exist (D175).
 *
 * Every `CacheVersion::bump()` in the system is a **content** event — a section or page published, an
 * FAQ, a CTA, a setting changed. Nothing bumped when `npm run build` ran. But a stored page is finished
 * HTML with `@vite`'s content-hashed asset URLs baked into it, so a rebuild renamed every asset and left
 * every stored page requesting files that had just been deleted.
 *
 * **This is written from a live failure, not from a hypothesis.** knsoftic.com served an entirely
 * unstyled site for exactly this reason: `GET /build/assets/app-BqxQUaBx.css` answered **404** while the
 * freshly built `app-CuUr34mR.css` answered 200, and the home page kept answering 200 with the dead URL
 * inside it. The page cache does not expire on its own — the `max-age=300` on the response is what the
 * *browser* is told, while the stored copy lives until the version stamp moves — so it would have stayed
 * broken until somebody cleared the cache by hand, on every deploy, for ever.
 *
 * The test writes a real manifest file and changes it, because the failure is about the manifest's
 * identity and a mock of it would prove nothing about the thing that broke.
 */
final class PublicCacheSurvivesAssetRebuildTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private string $manifestPath;

    private ?string $originalManifest = null;

    private bool $createdBuildDirectory = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        $this->manifestPath = public_path('build/manifest.json');

        if (! File::isDirectory(dirname($this->manifestPath))) {
            File::makeDirectory(dirname($this->manifestPath), 0755, true);
            $this->createdBuildDirectory = true;
        } elseif (File::exists($this->manifestPath)) {
            $this->originalManifest = File::get($this->manifestPath);
        }
    }

    protected function tearDown(): void
    {
        // Put the developer's own build back exactly as it was: this test rewrites a real file.
        if ($this->originalManifest !== null) {
            File::put($this->manifestPath, $this->originalManifest);
        } elseif ($this->createdBuildDirectory) {
            File::deleteDirectory(dirname($this->manifestPath));
        } elseif (File::exists($this->manifestPath)) {
            File::delete($this->manifestPath);
        }

        // The file is back; the framework's memoised copy of it is not. Without this every test that
        // runs after this one in the same process renders against this test's fixture.
        $this->forgetViteManifest();

        parent::tearDown();
    }

    /**
     * The failure this closes: a rebuild renames the assets, and the stored page keeps the old names.
     */
    #[Test]
    public function rebuilding_the_assets_makes_every_stored_page_unreachable(): void
    {
        $this->writeManifest('app-AAAAAAAA.css', 'app-AAAAAAAA.js');

        $this->get('/')->assertOk();

        $before = $this->pageCacheKeys();
        $this->assertNotEmpty($before, 'Nothing was stored, so this test would pass for the wrong reason.');

        // What a deploy does: the same entries, new content hashes.
        $this->writeManifest('app-BBBBBBBB.css', 'app-BBBBBBBB.js');

        $this->get('/')->assertOk();

        $after = $this->pageCacheKeys();

        // The old entry is still *stored* — D22 invalidates by moving the key, never by deleting, because
        // the database cache store has no tags. What must be true is that the second request did not
        // find it: it missed, rendered, and stored under a key the rebuild had moved.
        $this->assertNotEmpty(
            array_values(array_diff($after, $before)),
            'The rebuild did not change the cache key, so the second request was answered from the copy '
                .'stored before it — HTML naming assets that the rebuild had just deleted. This is '
                .'precisely how knsoftic.com came to serve an entirely unstyled site.',
        );
    }

    /**
     * And the other half: a rebuild that changes nothing must not throw the cache away.
     *
     * Keying on the manifest's *contents* rather than its modification time is what makes this true. An
     * mtime key would look correct and quietly cost a full cold render of every public page after any
     * deploy, including one that only changed PHP.
     */
    #[Test]
    public function a_rebuild_that_produces_identical_assets_keeps_the_cache_warm(): void
    {
        $this->writeManifest('app-AAAAAAAA.css', 'app-AAAAAAAA.js');

        $this->get('/')->assertOk();
        $before = $this->pageCacheKeys();
        $this->assertNotEmpty($before);

        // Rewritten byte for byte, with a new modification time, as a rebuild of unchanged sources does.
        $this->writeManifest('app-AAAAAAAA.css', 'app-AAAAAAAA.js');
        touch($this->manifestPath, time() + 60);
        clearstatcache(true, $this->manifestPath);

        $this->get('/')->assertOk();

        $this->assertSame(
            $before,
            $this->pageCacheKeys(),
            'An identical rebuild invalidated the page cache, so every deploy pays a cold render it '
                .'did not need to.',
        );
    }

    /**
     * A manifest shaped like Vite's own, naming the two entries this application builds.
     */
    private function writeManifest(string $css, string $js): void
    {
        File::put($this->manifestPath, (string) json_encode([
            'resources/css/app.css' => ['file' => 'assets/'.$css, 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/'.$js, 'src' => 'resources/js/app.js', 'isEntry' => true],
        ], JSON_PRETTY_PRINT));

        clearstatcache(true, $this->manifestPath);
        $this->forgetViteManifest();
    }

    /**
     * Drop the framework's memoised copy of the manifest.
     *
     * `Illuminate\Foundation\Vite::$manifests` is a **static** keyed by path, filled the first time a
     * manifest is read and never re-read. Rewriting the file does not touch it, so without this a test
     * that changes the manifest hands every later test in the same process whatever fixture it last
     * wrote. That is not hypothetical: it is how this test first broke `ThemeAndLayoutTest`, which
     * failed with *"Unable to locate file in Vite manifest: resources/js/charts.js"* against a manifest
     * on disk that listed it perfectly well.
     *
     * `Vite::flush()` does not help — it clears `preloadedAssets` and nothing else — so reflection is
     * the only way in.
     */
    private function forgetViteManifest(): void
    {
        $manifests = new ReflectionProperty(Vite::class, 'manifests');
        $manifests->setAccessible(true);
        $manifests->setValue(null, []);
    }
}
