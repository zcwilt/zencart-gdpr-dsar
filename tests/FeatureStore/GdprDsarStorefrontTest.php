<?php

namespace Tests\PluginLocal\GdprDsar\FeatureStore;

use Tests\Support\Database\TestDb;
use Tests\Support\Traits\CustomerAccountConcerns;
use Tests\Support\Traits\PluginLocalTestConcerns;
use Tests\Support\zcInProcessFeatureTestCaseStore;

/**
 * @group serial
 * @group customer-account-write
 * @group plugin-filesystem
 */
class GdprDsarStorefrontTest extends zcInProcessFeatureTestCaseStore
{
    use CustomerAccountConcerns;
    use PluginLocalTestConcerns;

    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->bootPluginLocalTest(__FILE__);
        $this->installCurrentPluginThroughInstaller(__FILE__);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->getSslMainPage('gdpr_dsar')
            ->assertRedirect('main_page=login');
    }

    public function testLoggedInCustomerSeesDsarRequestPage(): void
    {
        $this->createCustomerAccountOrLogin('florida-basic1');

        $this->getSslMainPage('gdpr_dsar')
            ->assertOk()
            ->assertHeader('X-ZC-InProcess-Runner', 'storefront')
            ->assertSee('GDPR / DSAR Requests')
            ->assertSee('Accept Current Privacy Policy')
            ->assertSee('Export my personal data')
            ->assertSee('Erase/anonymize my personal data')
            ->assertSee('No requests submitted yet.');
    }

    public function testCustomerAcceptsPolicyAndSubmitsExportRequest(): void
    {
        $profile = $this->createCustomerAccountOrLogin('florida-basic2');
        $customerId = $this->getCustomerIdFromEmail($profile['email_address']);
        $this->assertNotNull($customerId);
        $this->disableDsarEmails();

        $policyPage = $this->getSslMainPage('gdpr_dsar')
            ->assertOk()
            ->assertSee('Accept Current Privacy Policy');

        $policyResponse = $this->postSslMainPage('gdpr_dsar', [
            'action' => 'accept_policy',
            'securityToken' => $policyPage->securityToken(),
            'accept_policy' => '1',
        ]);
        $policyResponse->assertRedirect('main_page=gdpr_dsar');

        $acceptedPage = $this->followRedirect($policyResponse)
            ->assertOk()
            ->assertSee('Privacy policy acceptance has been recorded.');

        $this->assertSame(1, $this->countConsentEvents((int)$customerId));

        $requestResponse = $this->postSslMainPage('gdpr_dsar', [
            'action' => 'submit',
            'securityToken' => $acceptedPage->securityToken(),
            'request_type' => 'export',
            'request_notes' => 'Please send my personal data export.',
            'submit_request' => '1',
        ]);
        $requestResponse->assertRedirect('main_page=gdpr_dsar');

        $this->followRedirect($requestResponse)
            ->assertOk()
            ->assertSee('Your request was submitted successfully.')
            ->assertSee('export')
            ->assertSee('submitted');

        $requestId = $this->latestRequestId((int)$customerId);
        $this->assertNotNull($requestId);
        $this->assertSame(1, $this->countAuditEvents((int)$requestId, (int)$customerId, 'submit_export'));
    }

    public function testExpiredExportIsPurgedOnPageLoad(): void
    {
        $profile = $this->createCustomerAccountOrLogin('US-not-florida-basic');
        $customerId = $this->getCustomerIdFromEmail($profile['email_address']);
        $this->assertNotNull($customerId);

        $requestId = $this->seedCompletedExportRequest((int)$customerId, $profile['email_address']);
        $exportFile = $this->seedExpiredExport($requestId, (int)$customerId);
        $this->assertFileExists($exportFile);
        $this->assertSame(1, $this->countExportRows($requestId));

        $this->getSslMainPage('gdpr_dsar')
            ->assertOk()
            ->assertSee('GDPR / DSAR Requests');

        $this->assertFileDoesNotExist($exportFile);
        $this->assertSame(0, $this->countExportRows($requestId));
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

    private function countConsentEvents(int $customerId): int
    {
        return (int) TestDb::selectValue(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'gdpr_consent_events WHERE customers_id = :customerId AND consent_type = :consentType',
            [
                ':customerId' => $customerId,
                ':consentType' => 'privacy_policy',
            ]
        );
    }

    private function latestRequestId(int $customerId): ?int
    {
        $requestId = TestDb::selectValue(
            'SELECT request_id FROM ' . DB_PREFIX . 'gdpr_dsar_requests WHERE customers_id = :customerId AND request_type = :requestType AND status = :status ORDER BY request_id DESC LIMIT 1',
            [
                ':customerId' => $customerId,
                ':requestType' => 'export',
                ':status' => 'submitted',
            ]
        );

        return $requestId === null ? null : (int) $requestId;
    }

    private function countAuditEvents(int $requestId, int $customerId, string $actionKey): int
    {
        return (int) TestDb::selectValue(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'gdpr_dsar_audit_log WHERE request_id = :requestId AND customers_id = :customerId AND action_key = :actionKey',
            [
                ':requestId' => $requestId,
                ':customerId' => $customerId,
                ':actionKey' => $actionKey,
            ]
        );
    }

    private function seedCompletedExportRequest(int $customerId, string $email): int
    {
        return TestDb::insert(DB_PREFIX . 'gdpr_dsar_requests', [
            'customers_id' => $customerId,
            'customer_email_snapshot' => $email,
            'request_type' => 'export',
            'status' => 'completed',
            'request_source' => 'account',
            'request_notes' => 'Completed export with expired download.',
            'date_submitted' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'date_processed' => date('Y-m-d H:i:s', strtotime('-9 days')),
            'last_updated' => date('Y-m-d H:i:s', strtotime('-9 days')),
        ]);
    }

    private function seedExpiredExport(int $requestId, int $customerId): string
    {
        $exportDir = ROOTCWD . 'cache/gdpr_dsar_exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0775, true);
        }

        $filePath = $exportDir . '/expired-export-' . $requestId . '.zip';
        file_put_contents($filePath, 'expired export placeholder');

        TestDb::insert(DB_PREFIX . 'gdpr_dsar_exports', [
            'request_id' => $requestId,
            'customers_id' => $customerId,
            'download_token' => bin2hex(random_bytes(24)),
            'file_path' => $filePath,
            'file_size' => filesize($filePath),
            'file_checksum' => hash_file('sha256', $filePath),
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'download_count' => 0,
            'date_created' => date('Y-m-d H:i:s', strtotime('-9 days')),
        ]);

        return $filePath;
    }

    private function countExportRows(int $requestId): int
    {
        return (int) TestDb::selectValue(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'gdpr_dsar_exports WHERE request_id = :requestId',
            [':requestId' => $requestId]
        );
    }
}
