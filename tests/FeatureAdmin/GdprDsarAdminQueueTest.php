<?php

namespace Tests\PluginLocal\GdprDsar\FeatureAdmin;

use Tests\Support\Database\TestDb;
use Tests\Support\Traits\PluginLocalTestConcerns;
use Tests\Support\zcInProcessFeatureTestCaseAdmin;

/**
 * @group serial
 * @group custom-seeder
 * @group plugin-filesystem
 */
class GdprDsarAdminQueueTest extends zcInProcessFeatureTestCaseAdmin
{
    use PluginLocalTestConcerns;

    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->bootPluginLocalTest(__FILE__);
    }

    public function testAdminQueueShowsAndApprovesSubmittedRequest(): void
    {
        $this->prepareInstalledPlugin();

        $requestId = $this->seedSubmittedExportRequest();

        $this->loginAsAdmin();

        $this->visitAdminCommand('gdpr_dsar_admin')
            ->assertOk()
            ->assertSee('GDPR / DSAR Request Queue')
            ->assertSee('queue-test@example.com')
            ->assertSee('Please approve my export.')
            ->assertSee('submitted');

        $response = $this->visitAdminCommand('gdpr_dsar_admin&action=approve&request_id=' . $requestId);

        $this->followAdminRedirect($response)
            ->assertOk()
            ->assertSee('approved');

        $this->assertSame('approved', $this->requestStatus($requestId));
        $this->assertSame(1, $this->countAuditEvents($requestId, 'approve_request'));
    }

    public function testAdminQueueShowsSlaDueSoonAndOverdueRequests(): void
    {
        $this->prepareInstalledPlugin();

        $this->seedSubmittedExportRequest('on-track@example.com', 'On track request.', '-5 days');
        $this->seedSubmittedExportRequest('due-soon@example.com', 'Due soon request.', '-30 days +1 hour');
        $this->seedSubmittedExportRequest('overdue@example.com', 'Overdue request.', '-31 days');

        $this->loginAsAdmin();

        $this->visitAdminCommand('gdpr_dsar_admin')
            ->assertOk()
            ->assertSee('SLA Monitoring')
            ->assertSee('Open Requests:</strong> 3')
            ->assertSee('Due Soon:</strong> 1')
            ->assertSee('Overdue:</strong> 1')
            ->assertSee('on-track@example.com')
            ->assertSee('due-soon@example.com')
            ->assertSee('overdue@example.com')
            ->assertSee('On Track')
            ->assertSee('Due Soon')
            ->assertSee('Overdue');
    }

    public function testAdminProcessesApprovedExportRequest(): void
    {
        $this->prepareInstalledPlugin();

        $customerId = $this->seedCustomer('Export', 'Customer', 'export-customer@example.com');
        $requestId = $this->seedSubmittedExportRequest(
            'export-customer@example.com',
            'Please generate my export.',
            'now',
            $customerId
        );

        $this->loginAsAdmin();

        $approveResponse = $this->visitAdminCommand('gdpr_dsar_admin&action=approve&request_id=' . $requestId);
        $this->followAdminRedirect($approveResponse)
            ->assertOk()
            ->assertSee('approved');

        $processResponse = $this->visitAdminCommand('gdpr_dsar_admin&action=process&request_id=' . $requestId);
        $this->followAdminRedirect($processResponse)
            ->assertOk()
            ->assertSee('No DSAR requests found for the selected filter.');

        $this->assertSame('completed', $this->requestStatus($requestId));
        $this->assertSame(1, $this->countAuditEvents($requestId, 'process_export'));

        $export = $this->latestExport($requestId);
        $this->assertNotNull($export);
        $this->assertFileExists($export['file_path']);
        $this->assertGreaterThan(0, (int)$export['file_size']);
        $this->assertSame(0, (int)$export['download_count']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($export['file_path']));
        $this->assertNotFalse($zip->locateName('profile.json'));
        $this->assertStringContainsString('export-customer@example.com', (string)$zip->getFromName('profile.json'));
        $zip->close();

        @unlink($export['file_path']);
    }

    private function prepareInstalledPlugin(): void
    {
        $this->runCustomSeeder('StoreWizardSeeder');
        $this->installCurrentPluginThroughInstaller(__FILE__);
        $this->disableDsarEmails();
    }

    private function loginAsAdmin(): void
    {
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk()
            ->assertSee('Admin Home');
    }

    private function disableDsarEmails(): void
    {
        TestDb::update(
            DB_PREFIX . 'configuration',
            ['configuration_value' => 'false'],
            'configuration_key IN (:customerEmails, :adminEmails)',
            [
                ':customerEmails' => 'GDPR_DSAR_SEND_CUSTOMER_EMAILS',
                ':adminEmails' => 'GDPR_DSAR_NOTIFY_ADMIN_NEW_REQUEST',
            ]
        );
    }

    private function seedSubmittedExportRequest(
        string $email = 'queue-test@example.com',
        string $notes = 'Please approve my export.',
        string $submittedModifier = 'now',
        int $customerId = 0
    ): int
    {
        $submittedAt = date('Y-m-d H:i:s', strtotime($submittedModifier));

        return TestDb::insert(DB_PREFIX . 'gdpr_dsar_requests', [
            'customers_id' => $customerId,
            'customer_email_snapshot' => $email,
            'request_type' => 'export',
            'status' => 'submitted',
            'request_source' => 'account',
            'request_notes' => $notes,
            'date_submitted' => $submittedAt,
            'last_updated' => $submittedAt,
        ]);
    }

    private function seedCustomer(string $firstname, string $lastname, string $email): int
    {
        $customerId = TestDb::insert(DB_PREFIX . 'customers', [
            'customers_gender' => 'm',
            'customers_firstname' => $firstname,
            'customers_lastname' => $lastname,
            'customers_dob' => '0001-01-01 00:00:00',
            'customers_email_address' => $email,
            'customers_nick' => '',
            'customers_default_address_id' => 0,
            'customers_telephone' => '555-0100',
            'customers_password' => password_hash('password', PASSWORD_DEFAULT),
            'customers_secret' => '',
            'customers_newsletter' => 0,
            'customers_group_pricing' => 0,
            'customers_email_format' => 'TEXT',
            'customers_authorization' => 0,
            'activation_required' => 0,
            'welcome_email_sent' => 1,
            'customers_referral' => '',
            'registration_ip' => '127.0.0.1',
            'last_login_ip' => '127.0.0.1',
            'customers_paypal_payerid' => '',
            'customers_paypal_ec' => 0,
            'customers_whole' => 0,
        ]);

        TestDb::insert(DB_PREFIX . 'customers_info', [
            'customers_info_id' => $customerId,
            'customers_info_date_account_created' => date('Y-m-d H:i:s'),
            'global_product_notifications' => 0,
        ]);

        return $customerId;
    }

    private function requestStatus(int $requestId): ?string
    {
        $status = TestDb::selectValue(
            'SELECT status FROM ' . DB_PREFIX . 'gdpr_dsar_requests WHERE request_id = :requestId LIMIT 1',
            [':requestId' => $requestId]
        );

        return $status === null ? null : (string) $status;
    }

    private function countAuditEvents(int $requestId, string $actionKey): int
    {
        return (int) TestDb::selectValue(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'gdpr_dsar_audit_log WHERE request_id = :requestId AND action_key = :actionKey',
            [
                ':requestId' => $requestId,
                ':actionKey' => $actionKey,
            ]
        );
    }

    private function latestExport(int $requestId): ?array
    {
        return TestDb::selectOne(
            'SELECT export_id, file_path, file_size, download_count FROM ' . DB_PREFIX . 'gdpr_dsar_exports WHERE request_id = :requestId ORDER BY export_id DESC LIMIT 1',
            [':requestId' => $requestId]
        );
    }
}
