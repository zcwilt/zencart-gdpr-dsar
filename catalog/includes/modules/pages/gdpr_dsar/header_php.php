<?php
/**
 * GDPR/DSAR request page
 */

if (!zen_is_logged_in()) {
    $_SESSION['navigation']->set_snapshot();
    zen_redirect(zen_href_link(FILENAME_LOGIN, '', 'SSL'));
}

require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));

$breadcrumb->add(NAVBAR_TITLE);

$customerId = (int)$_SESSION['customer_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function gdprDsarSendEmailSafe(string $toName, string $toEmail, string $subject, string $body, string $module = 'gdpr_dsar'): void
{
    $result = zen_mail($toName, $toEmail, $subject, $body, STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => nl2br($body)], $module);
    if ($result === false || (is_string($result) && trim($result) !== '')) {
        error_log('GDPR/DSAR email send failed: ' . $subject . ' to ' . $toEmail);
    }
}

function gdprDsarCatalogWriteAudit($db, int $requestId, int $customerId, string $actionKey, string $notes = ''): void
{
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $sqlData = [
        ['fieldName' => 'request_id', 'value' => $requestId, 'type' => 'integer'],
        ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
        ['fieldName' => 'admin_id', 'value' => 0, 'type' => 'integer'],
        ['fieldName' => 'actor_type', 'value' => 'customer', 'type' => 'string'],
        ['fieldName' => 'action_key', 'value' => $actionKey, 'type' => 'string'],
        ['fieldName' => 'action_notes', 'value' => $notes, 'type' => 'stringIgnoreNull'],
        ['fieldName' => 'ip_hash', 'value' => $ipHash, 'type' => 'string'],
        ['fieldName' => 'date_added', 'value' => 'now()', 'type' => 'passthru'],
    ];
    $db->perform(TABLE_GDPR_DSAR_AUDIT_LOG, $sqlData);
}

function gdprDsarGetActivePolicyVersion($db, string $policyType): string
{
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

function gdprDsarGetLatestAcceptedPolicyVersion($db, int $customerId, string $policyType): string
{
    if (!defined('TABLE_GDPR_CONSENT_EVENTS')) {
        return '';
    }

    $event = $db->Execute(
        "SELECT policy_version
           FROM " . TABLE_GDPR_CONSENT_EVENTS . "
          WHERE customers_id = " . (int)$customerId . "
            AND consent_type = '" . zen_db_input($policyType . '_policy') . "'
            AND consent_status = 'accepted'
          ORDER BY event_id DESC
          LIMIT 1"
    );

    return $event->EOF ? '' : (string)$event->fields['policy_version'];
}

function gdprDsarRecordPolicyAcceptance($db, int $customerId, string $policyType, string $policyVersion, string $sourcePage): void
{
    if (!defined('TABLE_GDPR_CONSENT_EVENTS')) {
        return;
    }

    $db->perform(TABLE_GDPR_CONSENT_EVENTS, [
        ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
        ['fieldName' => 'consent_type', 'value' => $policyType . '_policy', 'type' => 'string'],
        ['fieldName' => 'consent_status', 'value' => 'accepted', 'type' => 'string'],
        ['fieldName' => 'source_page', 'value' => $sourcePage, 'type' => 'string'],
        ['fieldName' => 'policy_version', 'value' => $policyVersion, 'type' => 'string'],
        ['fieldName' => 'ip_hash', 'value' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 'type' => 'string'],
        ['fieldName' => 'date_added', 'value' => 'now()', 'type' => 'passthru'],
    ]);
}

$gdprActivePolicyVersion = gdprDsarGetActivePolicyVersion($db, 'privacy');
$gdprAcceptedPolicyVersion = gdprDsarGetLatestAcceptedPolicyVersion($db, $customerId, 'privacy');
$gdprNeedsPolicyAcceptance = ($gdprActivePolicyVersion !== '' && $gdprAcceptedPolicyVersion !== $gdprActivePolicyVersion);
$gdprPrivacyPolicyPage = defined('FILENAME_PRIVACY') ? FILENAME_PRIVACY : 'privacy';
$gdprPrivacyPolicyLink = zen_href_link($gdprPrivacyPolicyPage, '', 'SSL');

if ($action === 'download') {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));
    if ($token === '') {
        $messageStack->add_session('gdpr_dsar', TEXT_EXPORT_NOT_AVAILABLE, 'error');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'));
    }

    $sql = "SELECT e.export_id, e.file_path, e.file_size, e.download_count
              FROM " . TABLE_GDPR_DSAR_EXPORTS . " e
              INNER JOIN " . TABLE_GDPR_DSAR_REQUESTS . " r ON r.request_id = e.request_id
             WHERE e.download_token = :token:
               AND e.customers_id = :customersID:
               AND r.customers_id = :customersID:
               AND e.expires_at > now()
             LIMIT 1";
    $sql = $db->bindVars($sql, ':token:', $token, 'string');
    $sql = $db->bindVars($sql, ':customersID:', $customerId, 'integer');
    $export = $db->Execute($sql);

    if ($export->EOF || !file_exists($export->fields['file_path'])) {
        $messageStack->add_session('gdpr_dsar', TEXT_EXPORT_NOT_AVAILABLE, 'error');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'));
    }

    $db->Execute(
        "UPDATE " . TABLE_GDPR_DSAR_EXPORTS . "
            SET download_count = download_count + 1,
                last_downloaded_at = now()
          WHERE export_id = " . (int)$export->fields['export_id']
    );

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="gdpr-dsar-export-' . (int)$export->fields['export_id'] . '.zip"');
    header('Content-Length: ' . (int)$export->fields['file_size']);
    readfile($export->fields['file_path']);
    exit;
}

if ($action === 'accept_policy' && ($_POST['accept_policy'] ?? '') === '1') {
    if ($gdprActivePolicyVersion === '') {
        $messageStack->add('gdpr_dsar', TEXT_POLICY_ACCEPTANCE_FAILED, 'error');
    } elseif ($gdprNeedsPolicyAcceptance) {
        gdprDsarRecordPolicyAcceptance($db, $customerId, 'privacy', $gdprActivePolicyVersion, 'gdpr_dsar');
        $messageStack->add_session('gdpr_dsar', TEXT_POLICY_ACCEPTANCE_RECORDED, 'success');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'));
    }
}

if ($action === 'submit' && ($_POST['submit_request'] ?? '') !== '') {
    if ($gdprNeedsPolicyAcceptance) {
        $messageStack->add('gdpr_dsar', TEXT_POLICY_ACCEPTANCE_REQUIRED, 'error');
    }

    $requestType = preg_replace('/[^a-z]/', '', strtolower($_POST['request_type'] ?? ''));
    $requestNotes = trim(zen_db_prepare_input($_POST['request_notes'] ?? ''));
    $validTypes = ['export', 'erasure'];

    if ($gdprNeedsPolicyAcceptance) {
        // policy acceptance required first; message already queued.
    } elseif (!in_array($requestType, $validTypes, true)) {
        $messageStack->add('gdpr_dsar', TEXT_REQUEST_TYPE_INVALID, 'error');
    } else {
        $maxActive = (int)(defined('GDPR_DSAR_MAX_ACTIVE_REQUESTS_PER_TYPE') ? GDPR_DSAR_MAX_ACTIVE_REQUESTS_PER_TYPE : 1);
        $active = $db->Execute(
            "SELECT COUNT(*) AS total
               FROM " . TABLE_GDPR_DSAR_REQUESTS . "
              WHERE customers_id = " . $customerId . "
                AND request_type = '" . zen_db_input($requestType) . "'
                AND status IN ('submitted', 'approved', 'processing')"
        );

        if ((int)$active->fields['total'] >= max(1, $maxActive)) {
            $messageStack->add('gdpr_dsar', TEXT_REQUEST_LIMIT_REACHED, 'error');
        } else {
            $customer = $db->Execute(
                "SELECT customers_email_address
                   FROM " . TABLE_CUSTOMERS . "
                  WHERE customers_id = " . $customerId . "
                  LIMIT 1"
            );

            $sqlData = [
                ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
                ['fieldName' => 'customer_email_snapshot', 'value' => $customer->fields['customers_email_address'] ?? '', 'type' => 'string'],
                ['fieldName' => 'request_type', 'value' => $requestType, 'type' => 'string'],
                ['fieldName' => 'status', 'value' => 'submitted', 'type' => 'string'],
                ['fieldName' => 'request_source', 'value' => 'account', 'type' => 'string'],
                ['fieldName' => 'request_notes', 'value' => $requestNotes, 'type' => 'stringIgnoreNull'],
                ['fieldName' => 'date_submitted', 'value' => 'now()', 'type' => 'passthru'],
                ['fieldName' => 'last_updated', 'value' => 'now()', 'type' => 'passthru'],
            ];
            $db->perform(TABLE_GDPR_DSAR_REQUESTS, $sqlData);
            $requestId = (int)$db->insert_ID();

            gdprDsarCatalogWriteAudit($db, $requestId, $customerId, 'submit_' . $requestType, $requestNotes);

            if (defined('GDPR_DSAR_SEND_CUSTOMER_EMAILS') && GDPR_DSAR_SEND_CUSTOMER_EMAILS === 'true') {
                $fullName = trim(($customer->fields['customers_firstname'] ?? '') . ' ' . ($customer->fields['customers_lastname'] ?? ''));
                $fullName = ($fullName === '') ? (($customer->fields['customers_email_address'] ?? 'customer')) : $fullName;
                $subject = defined('EMAIL_GDPR_DSAR_SUBMITTED_SUBJECT') ? EMAIL_GDPR_DSAR_SUBMITTED_SUBJECT : 'DSAR request received';
                $body = sprintf(
                    defined('EMAIL_GDPR_DSAR_SUBMITTED_BODY') ? EMAIL_GDPR_DSAR_SUBMITTED_BODY : "Hello %s,\n\nWe have received your DSAR request (%s).\nRequest ID: %d\nStatus: submitted\n\nRegards,\n%s",
                    $fullName,
                    $requestType,
                    $requestId,
                    STORE_NAME
                );
                gdprDsarSendEmailSafe($fullName, (string)($customer->fields['customers_email_address'] ?? ''), $subject, $body, 'gdpr_dsar_customer');
            }

            if (defined('GDPR_DSAR_NOTIFY_ADMIN_NEW_REQUEST') && GDPR_DSAR_NOTIFY_ADMIN_NEW_REQUEST === 'true') {
                $adminEmail = STORE_OWNER_EMAIL_ADDRESS;
                $subject = defined('EMAIL_GDPR_DSAR_ADMIN_NEW_SUBJECT') ? EMAIL_GDPR_DSAR_ADMIN_NEW_SUBJECT : 'New DSAR request submitted';
                $body = sprintf(
                    defined('EMAIL_GDPR_DSAR_ADMIN_NEW_BODY') ? EMAIL_GDPR_DSAR_ADMIN_NEW_BODY : "A customer has submitted a DSAR request.\n\nRequest ID: %d\nCustomer ID: %d\nCustomer Email: %s\nRequest Type: %s\nSubmitted: %s",
                    $requestId,
                    $customerId,
                    (string)($customer->fields['customers_email_address'] ?? ''),
                    $requestType,
                    date('Y-m-d H:i:s')
                );
                gdprDsarSendEmailSafe(STORE_OWNER, $adminEmail, $subject, $body, 'gdpr_dsar_admin_notice');
            }

            $messageStack->add_session('gdpr_dsar', TEXT_REQUEST_SUBMITTED, 'success');
            zen_redirect(zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'));
        }
    }
}

$requests = $db->Execute(
    "SELECT r.request_id, r.request_type, r.status, r.date_submitted, r.date_processed,
            e.download_token, e.expires_at
       FROM " . TABLE_GDPR_DSAR_REQUESTS . " r
       LEFT JOIN " . TABLE_GDPR_DSAR_EXPORTS . " e
              ON e.request_id = r.request_id
      WHERE r.customers_id = " . $customerId . "
      ORDER BY r.request_id DESC"
);

$dsarRequests = [];
foreach ($requests as $row) {
    $row['download_available'] = (
        $row['request_type'] === 'export'
        && $row['status'] === 'completed'
        && !empty($row['download_token'])
        && !empty($row['expires_at'])
        && strtotime($row['expires_at']) > time()
    );
    $dsarRequests[] = $row;
}
