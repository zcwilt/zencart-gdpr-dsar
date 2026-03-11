<?php

class zcObserverGdprDsarConsent extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD',
            'NOTIFY_HEADER_ACCOUNT_NEWSLETTER_UPDATED',
        ]);
    }

    public function updateNotifyModuleCreateAccountAddedCustomerRecord(&$class, $eventID, $paramsArray)
    {
        global $db;

        if (!defined('TABLE_GDPR_CONSENT_EVENTS')) {
            return;
        }

        $customerId = (int)($paramsArray['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $newsletterStatus = '0';
        foreach ($paramsArray as $entry) {
            if (!is_array($entry) || ($entry['fieldName'] ?? '') !== 'customers_newsletter') {
                continue;
            }
            $newsletterStatus = (string)($entry['value'] ?? '0');
        }

        $policyVersion = $this->getActivePolicyVersion('privacy');

        $db->perform(TABLE_GDPR_CONSENT_EVENTS, [
            ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
            ['fieldName' => 'consent_type', 'value' => 'newsletter', 'type' => 'string'],
            ['fieldName' => 'consent_status', 'value' => ((int)$newsletterStatus === 1 ? 'accepted' : 'declined'), 'type' => 'string'],
            ['fieldName' => 'source_page', 'value' => 'create_account', 'type' => 'string'],
            ['fieldName' => 'policy_version', 'value' => $policyVersion, 'type' => 'string'],
            ['fieldName' => 'ip_hash', 'value' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 'type' => 'string'],
            ['fieldName' => 'date_added', 'value' => 'now()', 'type' => 'passthru'],
        ]);
    }

    public function updateNotifyHeaderAccountNewsletterUpdated(&$class, $eventID, $paramsArray)
    {
        global $db;

        if (!defined('TABLE_GDPR_CONSENT_EVENTS') || !zen_is_logged_in()) {
            return;
        }

        $customerId = (int)($_SESSION['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $policyVersion = $this->getActivePolicyVersion('privacy');

        $db->perform(TABLE_GDPR_CONSENT_EVENTS, [
            ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
            ['fieldName' => 'consent_type', 'value' => 'newsletter', 'type' => 'string'],
            ['fieldName' => 'consent_status', 'value' => ((int)$paramsArray === 1 ? 'accepted' : 'declined'), 'type' => 'string'],
            ['fieldName' => 'source_page', 'value' => 'account_newsletters', 'type' => 'string'],
            ['fieldName' => 'policy_version', 'value' => $policyVersion, 'type' => 'string'],
            ['fieldName' => 'ip_hash', 'value' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 'type' => 'string'],
            ['fieldName' => 'date_added', 'value' => 'now()', 'type' => 'passthru'],
        ]);
    }

    private function getActivePolicyVersion(string $policyType): string
    {
        global $db;

        if (!defined('TABLE_GDPR_POLICY_VERSIONS')) {
            return '';
        }

        $policy = $db->Execute(
            "SELECT version_label
               FROM " . TABLE_GDPR_POLICY_VERSIONS . "
              WHERE policy_type = '" . zen_db_input($policyType) . "'
                AND is_active = 1
              ORDER BY policy_id DESC
              LIMIT 1"
        );

        return $policy->EOF ? '' : (string)$policy->fields['version_label'];
    }
}
