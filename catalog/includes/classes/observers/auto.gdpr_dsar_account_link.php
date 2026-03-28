<?php

class zcObserverGdprDsarAccountLink extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_HEADER_END_ACCOUNT',
            'NOTIFY_ZEN_IS_LOGGED_IN',
        ]);
    }

    public function updateNotifyHeaderEndAccount(&$class, $eventID, $paramsArray)
    {
        global $messageStack, $db;

        if (!defined('FILENAME_GDPR_DSAR') || !zen_is_logged_in() || !defined('TABLE_GDPR_POLICY_VERSIONS')) {
            return;
        }

        $customerId = (int)($_SESSION['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $activePolicy = $db->Execute(
            "SELECT version_label
               FROM " . TABLE_GDPR_POLICY_VERSIONS . "
              WHERE policy_type = 'privacy'
                AND is_active = 1
              ORDER BY policy_id DESC
              LIMIT 1"
        );
        $activeVersion = $activePolicy->EOF ? '' : (string)$activePolicy->fields['version_label'];

        $acceptedVersion = '';
        if (defined('TABLE_GDPR_CONSENT_EVENTS')) {
            $accepted = $db->Execute(
                "SELECT policy_version
                   FROM " . TABLE_GDPR_CONSENT_EVENTS . "
                  WHERE customers_id = " . $customerId . "
                    AND consent_type = 'privacy_policy'
                    AND consent_status = 'accepted'
                  ORDER BY event_id DESC
                  LIMIT 1"
            );
            $acceptedVersion = $accepted->EOF ? '' : (string)$accepted->fields['policy_version'];
        }

        $text = defined('TEXT_PRIVACY_REQUEST_LINK') ? TEXT_PRIVACY_REQUEST_LINK : 'Manage privacy data requests';
        $link = '<a href="' . zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL') . '">' . $text . '</a>';
        $privacyPage = defined('FILENAME_PRIVACY') ? FILENAME_PRIVACY : 'privacy';
        $privacyText = defined('TEXT_PRIVACY_POLICY_LINK') ? TEXT_PRIVACY_POLICY_LINK : 'Privacy Policy';
        $privacyLink = '<a href="' . zen_href_link($privacyPage, '', 'SSL') . '">' . $privacyText . '</a>';

        if ($activeVersion !== '' && $acceptedVersion !== $activeVersion) {
            $notice = defined('TEXT_PRIVACY_POLICY_REACCEPT_NOTICE')
                ? TEXT_PRIVACY_POLICY_REACCEPT_NOTICE
                : 'A new privacy policy version is active. Please review and accept it before new privacy requests.';
            $messageStack->add('account', $notice . ' ' . $privacyLink . ' ' . $link, 'warning');
            return;
        }

        $messageStack->add('account', $link, 'success');
    }

    public function updateNotifyZenIsLoggedIn(&$class, $eventID, $paramsArray, &$isLoggedIn)
    {
        global $db;

        if ($isLoggedIn !== true) {
            return;
        }

        $customerId = (int)($_SESSION['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $customer = $db->Execute(
            "SELECT customers_id, customers_email_address, customers_password
               FROM " . TABLE_CUSTOMERS . "
              WHERE customers_id = " . $customerId . "
              LIMIT 1"
        );

        if ($customer->EOF) {
            $this->invalidateCustomerSession();
            $isLoggedIn = false;
            return;
        }

        $email = trim((string)($customer->fields['customers_email_address'] ?? ''));
        $password = (string)($customer->fields['customers_password'] ?? '');

        if (strtolower($email) === 'deleted' || $password === '') {
            $this->invalidateCustomerSession();
            $isLoggedIn = false;
        }
    }

    private function invalidateCustomerSession(): void
    {
        unset(
            $_SESSION['customer_id'],
            $_SESSION['customer_first_name'],
            $_SESSION['customer_last_name'],
            $_SESSION['customer_default_address_id'],
            $_SESSION['customer_country_id'],
            $_SESSION['customer_zone_id'],
            $_SESSION['customers_authorization'],
            $_SESSION['customer_guest_id'],
            $_SESSION['sendto'],
            $_SESSION['billto'],
            $_SESSION['cart_address_id']
        );

        if (isset($_SESSION['cart']) && is_object($_SESSION['cart']) && method_exists($_SESSION['cart'], 'reset')) {
            $_SESSION['cart']->reset(true);
        }
    }
}
