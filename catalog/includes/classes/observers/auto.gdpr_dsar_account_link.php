<?php

class zcObserverGdprDsarAccountLink extends base
{
    public function __construct()
    {
        $this->attach($this, ['NOTIFY_HEADER_END_ACCOUNT']);
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
}
