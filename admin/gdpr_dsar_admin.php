<?php

require 'includes/application_top.php';

if (!defined('GDPR_DSAR_ENABLE') || GDPR_DSAR_ENABLE !== 'true') {
    $messageStack->add_session('GDPR/DSAR plugin is disabled in configuration.', 'warning');
}

$action = $_GET['action'] ?? '';
$requestId = (int)($_GET['request_id'] ?? 0);
$statusFilter = preg_replace('/[^a-z_]/i', '', $_GET['status'] ?? 'uncompleted');
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if (defined('TABLE_GDPR_DSAR_EXPORTS')) {
    gdprDsarAdminPurgeExpiredExports($db);
}

function gdprDsarAdminAllowedPolicyTypes(): array
{
    return ['privacy', 'terms'];
}

function gdprDsarAdminNormalizePolicyType(string $policyType): string
{
    $policyType = preg_replace('/[^a-z_]/', '', strtolower($policyType));
    if (!in_array($policyType, gdprDsarAdminAllowedPolicyTypes(), true)) {
        return 'privacy';
    }
    return $policyType;
}

function gdprDsarAdminSendEmailSafe(string $toName, string $toEmail, string $subject, string $body, string $module = 'gdpr_dsar_admin'): void
{
    if (trim($toEmail) === '') {
        return;
    }

    $result = zen_mail($toName, $toEmail, $subject, $body, STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => nl2br($body)], $module);
    if ($result === false || (is_string($result) && trim($result) !== '')) {
        error_log('GDPR/DSAR email send failed: ' . $subject . ' to ' . $toEmail);
    }
}

function gdprDsarAdminGetCustomerContact($db, array $request): array
{
    $customerId = (int)($request['customers_id'] ?? 0);
    $fallbackEmail = (string)($request['customer_email_snapshot'] ?? '');
    $fallbackName = $fallbackEmail !== '' ? $fallbackEmail : 'Customer';

    $customer = $db->Execute(
        "SELECT customers_firstname, customers_lastname, customers_email_address
           FROM " . TABLE_CUSTOMERS . "
          WHERE customers_id = " . $customerId . "
          LIMIT 1"
    );

    if ($customer->EOF) {
        return ['name' => $fallbackName, 'email' => $fallbackEmail];
    }

    $name = trim(($customer->fields['customers_firstname'] ?? '') . ' ' . ($customer->fields['customers_lastname'] ?? ''));
    if ($name === '') {
        $name = (string)($customer->fields['customers_email_address'] ?? $fallbackName);
    }
    $email = (string)($customer->fields['customers_email_address'] ?? $fallbackEmail);

    return ['name' => $name, 'email' => $email];
}

function gdprDsarAdminGetExportDir(): string
{
    $relative = defined('GDPR_DSAR_EXPORT_STORAGE_RELATIVE') ? GDPR_DSAR_EXPORT_STORAGE_RELATIVE : 'cache/gdpr_dsar_exports';
    $relative = ltrim($relative, '/');
    $dir = DIR_FS_CATALOG . $relative;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/') . '/';
}

function gdprDsarAdminPurgeExpiredExports($db): void
{
    $expired = $db->Execute(
        "SELECT export_id, file_path
           FROM " . TABLE_GDPR_DSAR_EXPORTS . "
          WHERE expires_at <= now()"
    );

    foreach ($expired as $row) {
        $filePath = (string)($row['file_path'] ?? '');
        if ($filePath !== '' && file_exists($filePath)) {
            @unlink($filePath);
        }

        $db->Execute(
            "DELETE FROM " . TABLE_GDPR_DSAR_EXPORTS . "
              WHERE export_id = " . (int)$row['export_id'] . "
              LIMIT 1"
        );
    }
}

function gdprDsarAdminWriteAudit($db, int $requestId, int $customerId, int $adminId, string $actionKey, string $notes = ''): void
{
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $sqlData = [
        ['fieldName' => 'request_id', 'value' => $requestId, 'type' => 'integer'],
        ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
        ['fieldName' => 'admin_id', 'value' => $adminId, 'type' => 'integer'],
        ['fieldName' => 'actor_type', 'value' => 'admin', 'type' => 'string'],
        ['fieldName' => 'action_key', 'value' => $actionKey, 'type' => 'string'],
        ['fieldName' => 'action_notes', 'value' => $notes, 'type' => 'stringIgnoreNull'],
        ['fieldName' => 'ip_hash', 'value' => $ipHash, 'type' => 'string'],
        ['fieldName' => 'date_added', 'value' => 'now()', 'type' => 'passthru'],
    ];
    $db->perform(TABLE_GDPR_DSAR_AUDIT_LOG, $sqlData);
}

function gdprDsarAdminAnonymizeOrders($db, int $customerId): void
{
    $sql = "UPDATE " . TABLE_ORDERS . "
               SET customers_name = 'deleted',
                   customers_company = '',
                   customers_street_address = 'deleted',
                   customers_suburb = '',
                   customers_city = 'deleted',
                   customers_postcode = '',
                   customers_state = '',
                   customers_country = '',
                   customers_telephone = '',
                   customers_email_address = 'deleted',
                   delivery_name = 'deleted',
                   delivery_company = '',
                   delivery_street_address = 'deleted',
                   delivery_suburb = '',
                   delivery_city = 'deleted',
                   delivery_postcode = '',
                   delivery_state = '',
                   delivery_country = '',
                   billing_name = 'deleted',
                   billing_company = '',
                   billing_street_address = 'deleted',
                   billing_suburb = '',
                   billing_city = 'deleted',
                   billing_postcode = '',
                   billing_state = '',
                   billing_country = '',
                   cc_owner = '',
                   cc_number = '',
                   cc_expires = '',
                   ip_address = ''
             WHERE customers_id = " . (int)$customerId;
    $db->Execute($sql);
}

function gdprDsarAdminAnonymizeAddressBook($db, int $customerId): void
{
    $db->Execute(
        "UPDATE " . TABLE_ADDRESS_BOOK . "
            SET entry_gender = '',
                entry_company = '',
                entry_firstname = '',
                entry_lastname = 'deleted',
                entry_street_address = 'deleted',
                entry_suburb = '',
                entry_postcode = '',
                entry_city = 'deleted',
                entry_state = '',
                entry_country_id = 0,
                entry_zone_id = 0
          WHERE customers_id = " . (int)$customerId
    );

    $db->Execute(
        "UPDATE " . TABLE_CUSTOMERS . "
            SET customers_default_address_id = 0
          WHERE customers_id = " . (int)$customerId
    );
}

function gdprDsarAdminProcessExport($db, array $request, int $adminId): bool
{
    $customerId = (int)$request['customers_id'];

    $customer = $db->Execute(
        "SELECT customers_id, customers_firstname, customers_lastname, customers_email_address, customers_telephone,
                customers_newsletter, customers_dob, customers_gender, customers_nick
           FROM " . TABLE_CUSTOMERS . "
          WHERE customers_id = " . $customerId . "
          LIMIT 1"
    );
    if ($customer->EOF) {
        return false;
    }

    $addresses = $db->Execute(
        "SELECT * FROM " . TABLE_ADDRESS_BOOK . "
          WHERE customers_id = " . $customerId . "
          ORDER BY address_book_id ASC"
    );
    $orders = $db->Execute(
        "SELECT orders_id, customers_id, customers_name, customers_email_address, date_purchased, orders_status, order_total, currency
           FROM " . TABLE_ORDERS . "
          WHERE customers_id = " . $customerId . "
          ORDER BY orders_id ASC"
    );
    $reviews = $db->Execute(
        "SELECT reviews_id, products_id, date_added, last_modified, reviews_rating
           FROM " . TABLE_REVIEWS . "
          WHERE customers_id = " . $customerId . "
          ORDER BY reviews_id ASC"
    );

    $addressRows = [];
    foreach ($addresses as $row) {
        $addressRows[] = $row;
    }

    $orderRows = [];
    foreach ($orders as $row) {
        $orderRows[] = $row;
    }

    $reviewRows = [];
    foreach ($reviews as $row) {
        $reviewRows[] = $row;
    }

    $zip = new ZipArchive();
    $exportDir = gdprDsarAdminGetExportDir();
    $basename = sprintf('gdpr-dsar-export-%d-%d.zip', (int)$request['request_id'], time());
    $filePath = $exportDir . $basename;

    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $zip->addFromString('profile.json', json_encode($customer->fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('addresses.json', json_encode($addressRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('orders.json', json_encode($orderRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('reviews.json', json_encode($reviewRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();

    $token = bin2hex(random_bytes(24));
    $expiryDays = (int)(defined('GDPR_DSAR_EXPORT_EXPIRY_DAYS') ? GDPR_DSAR_EXPORT_EXPIRY_DAYS : 14);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, $expiryDays) * 86400));
    $checksum = hash_file('sha256', $filePath) ?: '';
    $fileSize = (int)(filesize($filePath) ?: 0);

    $sqlData = [
        ['fieldName' => 'request_id', 'value' => (int)$request['request_id'], 'type' => 'integer'],
        ['fieldName' => 'customers_id', 'value' => $customerId, 'type' => 'integer'],
        ['fieldName' => 'download_token', 'value' => $token, 'type' => 'string'],
        ['fieldName' => 'file_path', 'value' => $filePath, 'type' => 'string'],
        ['fieldName' => 'file_size', 'value' => $fileSize, 'type' => 'integer'],
        ['fieldName' => 'file_checksum', 'value' => $checksum, 'type' => 'string'],
        ['fieldName' => 'expires_at', 'value' => $expiresAt, 'type' => 'date'],
        ['fieldName' => 'download_count', 'value' => 0, 'type' => 'integer'],
        ['fieldName' => 'date_created', 'value' => 'now()', 'type' => 'passthru'],
    ];
    $db->perform(TABLE_GDPR_DSAR_EXPORTS, $sqlData);

    $sql = "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
               SET status = 'completed',
                   processed_by = " . $adminId . ",
                   date_processed = now(),
                   last_updated = now()
             WHERE request_id = " . (int)$request['request_id'];
    $db->Execute($sql);

    gdprDsarAdminWriteAudit($db, (int)$request['request_id'], $customerId, $adminId, 'process_export', 'Export generated.');

    return true;
}

function gdprDsarAdminProcessErasure($db, array $request, int $adminId): bool
{
    $customerId = (int)$request['customers_id'];
    $customer = new Customer($customerId);

    if ($customer->getData('customers_id') !== $customerId) {
        return false;
    }

    $customer->delete(false, true);
    gdprDsarAdminAnonymizeAddressBook($db, $customerId);
    gdprDsarAdminAnonymizeOrders($db, $customerId);

    $sql = "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
               SET status = 'completed',
                   processed_by = " . $adminId . ",
                   date_processed = now(),
                   last_updated = now()
             WHERE request_id = " . (int)$request['request_id'];
    $db->Execute($sql);

    gdprDsarAdminWriteAudit($db, (int)$request['request_id'], $customerId, $adminId, 'process_erasure', 'Erasure completed.');

    return true;
}

if (isset($_POST['policy_action']) && defined('TABLE_GDPR_POLICY_VERSIONS')) {
    $policyAction = preg_replace('/[^a-z_]/', '', strtolower((string)$_POST['policy_action']));
    $policyType = gdprDsarAdminNormalizePolicyType((string)($_POST['policy_type'] ?? 'privacy'));
    $statusParam = $statusFilter === '' ? '' : 'status=' . $statusFilter . '&';

    if ($policyAction === 'add_policy') {
        $versionLabel = trim((string)($_POST['version_label'] ?? ''));
        $notes = trim((string)($_POST['policy_notes'] ?? ''));
        $setActive = isset($_POST['set_active']) ? 1 : 0;

        if ($versionLabel === '') {
            $messageStack->add_session(TEXT_POLICY_VERSION_REQUIRED, 'error');
            zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
        }

        if ($setActive === 1) {
            $db->Execute(
                "UPDATE " . TABLE_GDPR_POLICY_VERSIONS . "
                    SET is_active = 0
                  WHERE policy_type = '" . zen_db_input($policyType) . "'"
            );
        }

        $db->perform(TABLE_GDPR_POLICY_VERSIONS, [
            ['fieldName' => 'policy_type', 'value' => $policyType, 'type' => 'string'],
            ['fieldName' => 'version_label', 'value' => $versionLabel, 'type' => 'string'],
            ['fieldName' => 'is_active', 'value' => $setActive, 'type' => 'integer'],
            ['fieldName' => 'published_at', 'value' => 'now()', 'type' => 'passthru'],
            ['fieldName' => 'notes', 'value' => $notes, 'type' => 'stringIgnoreNull'],
        ]);

        $messageStack->add_session(TEXT_POLICY_ADD_SUCCESS, 'success');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
    }

    if ($policyAction === 'activate_policy') {
        $policyId = (int)($_POST['policy_id'] ?? 0);
        if ($policyId <= 0) {
            $messageStack->add_session(TEXT_POLICY_ACTION_FAILURE, 'error');
            zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
        }

        $policy = $db->Execute(
            "SELECT policy_id, policy_type
               FROM " . TABLE_GDPR_POLICY_VERSIONS . "
              WHERE policy_id = " . $policyId . "
              LIMIT 1"
        );

        if ($policy->EOF) {
            $messageStack->add_session(TEXT_POLICY_ACTION_FAILURE, 'error');
            zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
        }

        $policyType = gdprDsarAdminNormalizePolicyType((string)$policy->fields['policy_type']);
        $db->Execute(
            "UPDATE " . TABLE_GDPR_POLICY_VERSIONS . "
                SET is_active = 0
              WHERE policy_type = '" . zen_db_input($policyType) . "'"
        );
        $db->Execute(
            "UPDATE " . TABLE_GDPR_POLICY_VERSIONS . "
                SET is_active = 1
              WHERE policy_id = " . $policyId
        );

        $messageStack->add_session(TEXT_POLICY_ACTIVATE_SUCCESS, 'success');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
    }
}

if ($requestId > 0 && in_array($action, ['approve', 'reject', 'process'], true)) {
    $check = $db->Execute(
        "SELECT * FROM " . TABLE_GDPR_DSAR_REQUESTS . "
          WHERE request_id = " . $requestId . "
          LIMIT 1"
    );

    if ($check->EOF) {
        $messageStack->add_session(TEXT_ACTION_FAILURE, 'error');
        zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN));
    }

    $request = $check->fields;
    $customerId = (int)$request['customers_id'];

    if ($action === 'approve' && $request['status'] === 'submitted') {
        $contact = gdprDsarAdminGetCustomerContact($db, $request);
        $db->Execute(
            "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
                SET status = 'approved', approved_by = " . $adminId . ", date_decided = now(), last_updated = now()
              WHERE request_id = " . $requestId
        );
        gdprDsarAdminWriteAudit($db, $requestId, $customerId, $adminId, 'approve_request');
        if (defined('GDPR_DSAR_SEND_CUSTOMER_EMAILS') && GDPR_DSAR_SEND_CUSTOMER_EMAILS === 'true') {
            $subject = defined('EMAIL_GDPR_DSAR_APPROVED_SUBJECT') ? EMAIL_GDPR_DSAR_APPROVED_SUBJECT : 'DSAR request approved';
            $body = sprintf(
                defined('EMAIL_GDPR_DSAR_APPROVED_BODY') ? EMAIL_GDPR_DSAR_APPROVED_BODY : "Hello %s,\n\nYour DSAR request has been approved.\nRequest ID: %d\nRequest Type: %s\nStatus: approved\n\nRegards,\n%s",
                $contact['name'],
                $requestId,
                (string)$request['request_type'],
                STORE_NAME
            );
            gdprDsarAdminSendEmailSafe($contact['name'], $contact['email'], $subject, $body, 'gdpr_dsar_status');
        }
        $messageStack->add_session(TEXT_ACTION_SUCCESS, 'success');
    }

    if ($action === 'reject' && in_array($request['status'], ['submitted', 'approved'], true)) {
        $contact = gdprDsarAdminGetCustomerContact($db, $request);
        $reason = trim((string)($request['admin_notes'] ?? ''));
        if ($reason === '') {
            $reason = 'Request rejected during manual review.';
        }
        $db->Execute(
            "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
                SET status = 'rejected', rejected_by = " . $adminId . ", date_decided = now(), last_updated = now()
              WHERE request_id = " . $requestId
        );
        gdprDsarAdminWriteAudit($db, $requestId, $customerId, $adminId, 'reject_request');
        if (defined('GDPR_DSAR_SEND_CUSTOMER_EMAILS') && GDPR_DSAR_SEND_CUSTOMER_EMAILS === 'true') {
            $subject = defined('EMAIL_GDPR_DSAR_REJECTED_SUBJECT') ? EMAIL_GDPR_DSAR_REJECTED_SUBJECT : 'DSAR request update';
            $body = sprintf(
                defined('EMAIL_GDPR_DSAR_REJECTED_BODY') ? EMAIL_GDPR_DSAR_REJECTED_BODY : "Hello %s,\n\nYour DSAR request has been rejected.\nRequest ID: %d\nRequest Type: %s\nStatus: rejected\n\nReason: %s\n\nRegards,\n%s",
                $contact['name'],
                $requestId,
                (string)$request['request_type'],
                $reason,
                STORE_NAME
            );
            gdprDsarAdminSendEmailSafe($contact['name'], $contact['email'], $subject, $body, 'gdpr_dsar_status');
        }
        $messageStack->add_session(TEXT_ACTION_SUCCESS, 'success');
    }

    if ($action === 'process' && $request['status'] === 'approved') {
        $contact = gdprDsarAdminGetCustomerContact($db, $request);
        $db->Execute(
            "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
                SET status = 'processing', last_updated = now()
              WHERE request_id = " . $requestId
        );

        $processed = false;
        try {
            if ($request['request_type'] === 'export') {
                $processed = gdprDsarAdminProcessExport($db, $request, $adminId);
                if ($processed) {
                    if (defined('GDPR_DSAR_SEND_CUSTOMER_EMAILS') && GDPR_DSAR_SEND_CUSTOMER_EMAILS === 'true') {
                        $subject = defined('EMAIL_GDPR_DSAR_EXPORT_READY_SUBJECT') ? EMAIL_GDPR_DSAR_EXPORT_READY_SUBJECT : 'Your DSAR export is ready';
                        $body = sprintf(
                            defined('EMAIL_GDPR_DSAR_EXPORT_READY_BODY') ? EMAIL_GDPR_DSAR_EXPORT_READY_BODY : "Hello %s,\n\nYour DSAR data export is ready.\nRequest ID: %d\n\nPlease log in to your account and open the Privacy Data Requests page to download your export.\n\nRegards,\n%s",
                            $contact['name'],
                            $requestId,
                            STORE_NAME
                        );
                        gdprDsarAdminSendEmailSafe($contact['name'], $contact['email'], $subject, $body, 'gdpr_dsar_complete');
                    }
                    $messageStack->add_session(TEXT_EXPORT_READY, 'success');
                }
            } else {
                $processed = gdprDsarAdminProcessErasure($db, $request, $adminId);
                if ($processed) {
                    if (defined('GDPR_DSAR_SEND_CUSTOMER_EMAILS') && GDPR_DSAR_SEND_CUSTOMER_EMAILS === 'true') {
                        $subject = defined('EMAIL_GDPR_DSAR_ERASURE_COMPLETE_SUBJECT') ? EMAIL_GDPR_DSAR_ERASURE_COMPLETE_SUBJECT : 'DSAR erasure completed';
                        $body = sprintf(
                            defined('EMAIL_GDPR_DSAR_ERASURE_COMPLETE_BODY') ? EMAIL_GDPR_DSAR_ERASURE_COMPLETE_BODY : "Hello %s,\n\nYour DSAR erasure/anonymization request has been completed.\nRequest ID: %d\n\nRegards,\n%s",
                            $contact['name'],
                            $requestId,
                            STORE_NAME
                        );
                        gdprDsarAdminSendEmailSafe($contact['name'], $contact['email'], $subject, $body, 'gdpr_dsar_complete');
                    }
                    $messageStack->add_session(TEXT_ERASURE_COMPLETE, 'success');
                }
            }
        } catch (Throwable $e) {
            error_log('GDPR/DSAR processing failure: ' . $e->getMessage());
            $processed = false;
        }

        if (!$processed) {
            $db->Execute(
                "UPDATE " . TABLE_GDPR_DSAR_REQUESTS . "
                    SET status = 'failed', last_updated = now()
                  WHERE request_id = " . $requestId
            );
            gdprDsarAdminWriteAudit($db, $requestId, $customerId, $adminId, 'process_failed');
            $messageStack->add_session(TEXT_PROCESS_FAILED, 'error');
        }
    }

    $statusParam = $statusFilter === '' ? '' : 'status=' . $statusFilter . '&';
    zen_redirect(zen_href_link(FILENAME_GDPR_DSAR_ADMIN, $statusParam));
}

$statuses = ['uncompleted', 'submitted', 'approved', 'processing', 'completed', 'rejected', 'failed'];
if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = 'uncompleted';
}

$where = '';
if ($statusFilter === 'uncompleted') {
    $where = " WHERE status <> 'completed'";
} elseif ($statusFilter !== '') {
    $where = " WHERE status = '" . zen_db_input($statusFilter) . "'";
}

$requests = $db->Execute(
    "SELECT request_id, customers_id, customer_email_snapshot, request_type, status, date_submitted, request_notes, admin_notes
       FROM " . TABLE_GDPR_DSAR_REQUESTS .
    $where .
    " ORDER BY request_id DESC"
);

$policyVersions = [];
if (defined('TABLE_GDPR_POLICY_VERSIONS')) {
    $policies = $db->Execute(
        "SELECT policy_id, policy_type, version_label, is_active, published_at, notes
           FROM " . TABLE_GDPR_POLICY_VERSIONS . "
          ORDER BY policy_type ASC, policy_id DESC"
    );
    foreach ($policies as $policy) {
        $policyVersions[] = $policy;
    }
}

?>
<!doctype html>
<html <?= HTML_PARAMS; ?>>
<head>
    <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="container-fluid">
    <h1><?= HEADING_TITLE; ?></h1>

    <h2><?= TEXT_POLICY_SECTION_TITLE; ?></h2>
    <form method="post" action="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, ($statusFilter !== '' ? 'status=' . $statusFilter : '')); ?>" class="form-inline" style="margin-bottom: 1rem;">
        <input type="hidden" name="cmd" value="<?= FILENAME_GDPR_DSAR_ADMIN; ?>">
        <input type="hidden" name="securityToken" value="<?= $_SESSION['securityToken']; ?>">
        <input type="hidden" name="policy_action" value="add_policy">
        <label for="policy_type"><?= TEXT_POLICY_TYPE; ?></label>
        <select id="policy_type" name="policy_type" class="form-control">
            <option value="privacy"><?= defined('TEXT_POLICY_TYPE_PRIVACY') ? TEXT_POLICY_TYPE_PRIVACY : 'Privacy'; ?></option>
            <option value="terms"><?= defined('TEXT_POLICY_TYPE_TERMS') ? TEXT_POLICY_TYPE_TERMS : 'Terms'; ?></option>
        </select>
        <label for="version_label"><?= TEXT_POLICY_VERSION; ?></label>
        <input id="version_label" type="text" name="version_label" class="form-control" required>
        <label for="policy_notes"><?= TEXT_POLICY_NOTES; ?></label>
        <input id="policy_notes" type="text" name="policy_notes" class="form-control">
        <label style="margin-left: .5rem;">
            <input type="checkbox" name="set_active" value="1"> <?= TEXT_POLICY_SET_ACTIVE; ?>
        </label>
        <button type="submit" class="btn btn-primary"><?= TEXT_POLICY_ADD; ?></button>
    </form>

    <table class="table table-striped table-bordered">
        <thead>
        <tr>
            <th><?= TEXT_POLICY_TYPE; ?></th>
            <th><?= TEXT_POLICY_VERSION; ?></th>
            <th><?= TEXT_POLICY_PUBLISHED; ?></th>
            <th><?= TEXT_POLICY_ACTIVE; ?></th>
            <th><?= TEXT_POLICY_NOTES; ?></th>
            <th><?= TEXT_ACTION; ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($policyVersions)): ?>
            <tr><td colspan="6"><?= TEXT_POLICY_NO_ROWS; ?></td></tr>
        <?php else: ?>
            <?php foreach ($policyVersions as $policy): ?>
                <?php
                $policyTypeText = zen_output_string_protected($policy['policy_type']);
                if ($policy['policy_type'] === 'privacy' && defined('TEXT_POLICY_TYPE_PRIVACY')) {
                    $policyTypeText = TEXT_POLICY_TYPE_PRIVACY;
                } elseif ($policy['policy_type'] === 'terms' && defined('TEXT_POLICY_TYPE_TERMS')) {
                    $policyTypeText = TEXT_POLICY_TYPE_TERMS;
                }
                ?>
                <tr>
                    <td><?= $policyTypeText; ?></td>
                    <td><?= zen_output_string_protected($policy['version_label']); ?></td>
                    <td><?= zen_output_string_protected($policy['published_at']); ?></td>
                    <td><?= ((int)$policy['is_active'] === 1 ? TEXT_YES : TEXT_NO); ?></td>
                    <td><?= !empty($policy['notes']) ? nl2br(zen_output_string_protected($policy['notes'])) : TEXT_NONE; ?></td>
                    <td>
                        <?php if ((int)$policy['is_active'] !== 1): ?>
                            <form method="post" action="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, ($statusFilter !== '' ? 'status=' . $statusFilter : '')); ?>" style="display:inline;">
                                <input type="hidden" name="cmd" value="<?= FILENAME_GDPR_DSAR_ADMIN; ?>">
                                <input type="hidden" name="securityToken" value="<?= $_SESSION['securityToken']; ?>">
                                <input type="hidden" name="policy_action" value="activate_policy">
                                <input type="hidden" name="policy_id" value="<?= (int)$policy['policy_id']; ?>">
                                <button type="submit" class="btn btn-xs btn-primary"><?= TEXT_POLICY_SET_ACTIVE; ?></button>
                            </form>
                        <?php else: ?>
                            <?= TEXT_NONE; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="get" action="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN); ?>" class="form-inline" style="margin-bottom: 1rem;">
        <input type="hidden" name="cmd" value="<?= FILENAME_GDPR_DSAR_ADMIN; ?>">
        <label for="status-filter"><?= TEXT_FILTER_STATUS; ?></label>
        <select id="status-filter" name="status" class="form-control">
            <option value=""><?= TEXT_ALL; ?></option>
            <option value="uncompleted"<?= ($statusFilter === 'uncompleted' ? ' selected' : ''); ?>>
                <?= defined('TEXT_UNCOMPLETED') ? TEXT_UNCOMPLETED : 'Uncompleted'; ?>
            </option>
            <?php foreach ($statuses as $status): ?>
                <?php if ($status === 'uncompleted') continue; ?>
                <option value="<?= zen_output_string_protected($status); ?>"<?= ($statusFilter === $status ? ' selected' : ''); ?>>
                    <?= zen_output_string_protected($status); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <table class="table table-striped table-bordered">
        <thead>
        <tr>
            <th><?= TEXT_REQUEST_ID; ?></th>
            <th><?= TEXT_CUSTOMER_ID; ?></th>
            <th><?= TEXT_EMAIL; ?></th>
            <th><?= TEXT_REQUEST_TYPE; ?></th>
            <th><?= TEXT_STATUS; ?></th>
            <th><?= TEXT_SUBMITTED; ?></th>
            <th><?= TEXT_NOTES; ?></th>
            <th><?= TEXT_ACTION; ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($requests->EOF): ?>
            <tr><td colspan="8"><?= TEXT_NO_REQUESTS; ?></td></tr>
        <?php else: ?>
            <?php foreach ($requests as $row): ?>
                <tr>
                    <td><?= (int)$row['request_id']; ?></td>
                    <td><?= (int)$row['customers_id']; ?></td>
                    <td><?= zen_output_string_protected($row['customer_email_snapshot']); ?></td>
                    <td><?= zen_output_string_protected($row['request_type']); ?></td>
                    <td><?= zen_output_string_protected($row['status']); ?></td>
                    <td><?= zen_output_string_protected($row['date_submitted']); ?></td>
                    <td><?= $row['request_notes'] !== '' ? nl2br(zen_output_string_protected($row['request_notes'])) : TEXT_NONE; ?></td>
                    <td>
                        <?php if ($row['status'] === 'submitted'): ?>
                            <a class="btn btn-xs btn-success" href="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, 'action=approve&request_id=' . (int)$row['request_id'] . ($statusFilter !== '' ? '&status=' . $statusFilter : '')); ?>"><?= TEXT_APPROVE; ?></a>
                            <a class="btn btn-xs btn-warning" href="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, 'action=reject&request_id=' . (int)$row['request_id'] . ($statusFilter !== '' ? '&status=' . $statusFilter : '')); ?>"><?= TEXT_REJECT; ?></a>
                        <?php elseif ($row['status'] === 'approved'): ?>
                            <a class="btn btn-xs btn-primary" href="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, 'action=process&request_id=' . (int)$row['request_id'] . ($statusFilter !== '' ? '&status=' . $statusFilter : '')); ?>"><?= TEXT_PROCESS; ?></a>
                            <a class="btn btn-xs btn-warning" href="<?= zen_href_link(FILENAME_GDPR_DSAR_ADMIN, 'action=reject&request_id=' . (int)$row['request_id'] . ($statusFilter !== '' ? '&status=' . $statusFilter : '')); ?>"><?= TEXT_REJECT; ?></a>
                        <?php else: ?>
                            <?= TEXT_NONE; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>
