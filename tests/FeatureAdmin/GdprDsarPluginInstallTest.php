<?php

namespace Tests\PluginLocal\GdprDsar\FeatureAdmin;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Traits\PluginLocalTestConcerns;
use Tests\Support\zcInProcessFeatureTestCaseAdmin;

#[Group('serial')]
#[Group('custom-seeder')]
#[Group('plugin-filesystem')]
class GdprDsarPluginInstallTest extends zcInProcessFeatureTestCaseAdmin
{
    use PluginLocalTestConcerns;

    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->bootPluginLocalTest(__FILE__);
    }

    public function testInstallPluginAndOpenAdminQueue(): void
    {
        $this->runCustomSeeder('StoreWizardSeeder');
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk()
            ->assertSee('Admin Home');

        $this->installCurrentPluginToFilesystem(__FILE__);
        $this->visitAdminCommand('plugin_manager')->assertOk();

        $response = $this->visitAdminCommand('plugin_manager&page=1&colKey=gdpr-dsar&action=install')
            ->assertOk()
            ->assertSee('GDPR / DSAR Manager');

        $expectedVersion = (string) ($response->formDefaults('plugininstall')['version'] ?? '');
        $this->assertNotSame('', $expectedVersion, 'Expected plugin-install form to include a selected version.');

        $this->submitAdminForm($response, 'plugininstall')
            ->assertOk()
            ->assertSee('Version Installed:</strong> ' . $expectedVersion);

        $this->visitAdminCommand('gdpr_dsar_admin')
            ->assertOk()
            ->assertSee('GDPR / DSAR Request Queue');

        $response = $this->visitAdminCommand('plugin_manager&page=1&colKey=gdpr-dsar&action=uninstall')
            ->assertOk()
            ->assertSee('Are you sure you want to uninstall this plugin?');

        $this->submitAdminForm($response, 'pluginuninstall')
            ->assertOk()
            ->assertSee('action=install');
    }
}
