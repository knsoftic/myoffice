<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 2 — phase-03 §13.4 "an honest setting beats a lying one".
 *
 * `website.cache_warm_enabled` switches a warm-up job and `website.revision_keep` bounds a revision pruner;
 * neither has shipped (§10.2, §10.4 deferred). Until they do, both settings are read-only and say "Not
 * active yet", so the settings tab never promises behaviour the system does not have. The day the job or
 * the pruner ships, this test fails and asks for the flag to be lifted.
 */
final class DeferredFeatureSettingsTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** setting key => the class whose absence makes it inert */
    private const DEFERRED = [
        'website.cache_warm_enabled' => 'App\\Jobs\\Cms\\WarmPublicPageCache',
        'website.revision_keep' => 'App\\Jobs\\Cms\\PruneCmsRevisions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_settings_for_unshipped_cms_jobs_are_read_only_and_say_so(): void
    {
        foreach (self::DEFERRED as $key => $class) {
            $field = SettingsRegistry::field($key);

            $this->assertNotNull($field, $key.' is declared.');
            $this->assertFalse(class_exists($class), sprintf('%s has shipped: make %s editable again and drop its "Not active yet" help.', $class, $key));
            $this->assertTrue($field['readonly'], sprintf('%s does nothing until %s ships, so it must not be editable.', $key, $class));
            $this->assertStringStartsWith('Not active yet', (string) $field['help']);
        }

        $admin = $this->createSuperAdmin();

        foreach (['cache_warm_enabled' => false, 'revision_keep' => 99] as $name => $value) {
            try {
                app(SettingsService::class)->update('website', [$name => $value], $admin);
                $this->fail(sprintf('website.%s was changed although it is read-only.', $name));
            } catch (ActionNotAllowedException) {
                $this->assertNotSame($value, settings_repo()->get('website.'.$name), sprintf('website.%s kept its value.', $name));
            }
        }
    }
}
