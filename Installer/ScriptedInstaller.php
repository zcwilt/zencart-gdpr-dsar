<?php

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    private const CONFIG_GROUP_TITLE = 'GDPR / DSAR Manager';
    private const TABLE_MAP = [
        'TABLE_GDPR_DSAR_REQUESTS' => 'gdpr_dsar_requests',
        'TABLE_GDPR_DSAR_EXPORTS' => 'gdpr_dsar_exports',
        'TABLE_GDPR_DSAR_AUDIT_LOG' => 'gdpr_dsar_audit_log',
        'TABLE_GDPR_CONSENT_EVENTS' => 'gdpr_consent_events',
        'TABLE_GDPR_POLICY_VERSIONS' => 'gdpr_policy_versions',
    ];

    protected function executeInstall()
    {
        $this->ensureTableConstants();
        $cgi = $this->getOrCreateConfigGroupId(self::CONFIG_GROUP_TITLE, self::CONFIG_GROUP_TITLE . ' Settings');

        $this->addConfigurationKey('GDPR_DSAR_ENABLE', [
            'configuration_title' => 'Enable GDPR/DSAR plugin?',
            'configuration_value' => 'true',
            'configuration_description' => 'Enable customer-side DSAR requests and admin processing tools.',
            'configuration_group_id' => $cgi,
            'sort_order' => 10,
            'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
        ]);

        $this->addConfigurationKey('GDPR_DSAR_EXPORT_EXPIRY_DAYS', [
            'configuration_title' => 'Export Link Expiry (days)',
            'configuration_value' => '14',
            'configuration_description' => 'Number of days before export download links expire.',
            'configuration_group_id' => $cgi,
            'sort_order' => 20,
        ]);

        $this->addConfigurationKey('GDPR_DSAR_MAX_ACTIVE_REQUESTS_PER_TYPE', [
            'configuration_title' => 'Max Active Requests Per Type',
            'configuration_value' => '1',
            'configuration_description' => 'Maximum active requests a customer can have per DSAR type.',
            'configuration_group_id' => $cgi,
            'sort_order' => 30,
        ]);

        $this->addConfigurationKey('GDPR_DSAR_EXPORT_STORAGE_RELATIVE', [
            'configuration_title' => 'Export Storage Folder',
            'configuration_value' => 'cache/gdpr_dsar_exports',
            'configuration_description' => 'Relative path from catalog root used to store generated export ZIP files.',
            'configuration_group_id' => $cgi,
            'sort_order' => 40,
        ]);

        $this->addConfigurationKey('GDPR_DSAR_SEND_CUSTOMER_EMAILS', [
            'configuration_title' => 'Send customer DSAR lifecycle emails?',
            'configuration_value' => 'true',
            'configuration_description' => 'Send customer notifications when DSAR requests are submitted, approved, rejected, and completed.',
            'configuration_group_id' => $cgi,
            'sort_order' => 50,
            'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
        ]);

        $this->addConfigurationKey('GDPR_DSAR_NOTIFY_ADMIN_NEW_REQUEST', [
            'configuration_title' => 'Notify admin on new DSAR requests?',
            'configuration_value' => 'true',
            'configuration_description' => 'Send notification email to store owner when a customer submits a new DSAR request.',
            'configuration_group_id' => $cgi,
            'sort_order' => 60,
            'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
        ]);

        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_GDPR_DSAR_REQUESTS . " (
                request_id int(11) NOT NULL auto_increment,
                customers_id int(11) NOT NULL,
                customer_email_snapshot varchar(96) NOT NULL default '',
                request_type varchar(16) NOT NULL,
                status varchar(16) NOT NULL default 'submitted',
                request_source varchar(32) NOT NULL default 'account',
                request_notes text,
                admin_notes text,
                approved_by int(11) DEFAULT NULL,
                rejected_by int(11) DEFAULT NULL,
                processed_by int(11) DEFAULT NULL,
                date_submitted datetime NOT NULL,
                date_decided datetime DEFAULT NULL,
                date_processed datetime DEFAULT NULL,
                last_updated datetime NOT NULL,
                PRIMARY KEY (request_id),
                KEY idx_gdpr_dsar_req_customer (customers_id),
                KEY idx_gdpr_dsar_req_status (status),
                KEY idx_gdpr_dsar_req_type_status (request_type, status)
            ) ENGINE=MyISAM"
        );

        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_GDPR_DSAR_EXPORTS . " (
                export_id int(11) NOT NULL auto_increment,
                request_id int(11) NOT NULL,
                customers_id int(11) NOT NULL,
                download_token varchar(64) NOT NULL,
                file_path varchar(255) NOT NULL,
                file_size bigint(20) unsigned NOT NULL default 0,
                file_checksum varchar(64) NOT NULL default '',
                expires_at datetime NOT NULL,
                download_count int(11) NOT NULL default 0,
                last_downloaded_at datetime DEFAULT NULL,
                date_created datetime NOT NULL,
                PRIMARY KEY (export_id),
                UNIQUE KEY idx_gdpr_dsar_exports_token (download_token),
                KEY idx_gdpr_dsar_exports_req (request_id),
                KEY idx_gdpr_dsar_exports_customer (customers_id)
            ) ENGINE=MyISAM"
        );

        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_GDPR_DSAR_AUDIT_LOG . " (
                audit_id int(11) NOT NULL auto_increment,
                request_id int(11) DEFAULT NULL,
                customers_id int(11) DEFAULT NULL,
                admin_id int(11) DEFAULT NULL,
                actor_type varchar(16) NOT NULL default 'system',
                action_key varchar(64) NOT NULL,
                action_notes text,
                ip_hash varchar(64) NOT NULL default '',
                date_added datetime NOT NULL,
                PRIMARY KEY (audit_id),
                KEY idx_gdpr_dsar_audit_req (request_id),
                KEY idx_gdpr_dsar_audit_customer (customers_id)
            ) ENGINE=MyISAM"
        );

        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_GDPR_CONSENT_EVENTS . " (
                event_id int(11) NOT NULL auto_increment,
                customers_id int(11) NOT NULL,
                consent_type varchar(32) NOT NULL,
                consent_status varchar(16) NOT NULL,
                source_page varchar(64) NOT NULL default '',
                policy_version varchar(32) NOT NULL default '',
                ip_hash varchar(64) NOT NULL default '',
                notes text,
                date_added datetime NOT NULL,
                PRIMARY KEY (event_id),
                KEY idx_gdpr_consent_customer (customers_id),
                KEY idx_gdpr_consent_type (consent_type)
            ) ENGINE=MyISAM"
        );

        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_GDPR_POLICY_VERSIONS . " (
                policy_id int(11) NOT NULL auto_increment,
                policy_type varchar(32) NOT NULL,
                version_label varchar(32) NOT NULL,
                is_active tinyint(1) NOT NULL default 0,
                published_at datetime NOT NULL,
                notes text,
                PRIMARY KEY (policy_id),
                KEY idx_gdpr_policy_type_active (policy_type, is_active)
            ) ENGINE=MyISAM"
        );

        zen_register_admin_page('toolsGdprDsar', 'BOX_TOOLS_GDPR_DSAR', 'FILENAME_GDPR_DSAR_ADMIN', '', 'customers', 'Y');

        parent::executeInstall();
        return true;
    }

    protected function executeUninstall()
    {
        $this->ensureTableConstants();
        zen_deregister_admin_pages(['toolsGdprDsar']);

        $this->deleteConfigurationKeys([
            'GDPR_DSAR_ENABLE',
            'GDPR_DSAR_EXPORT_EXPIRY_DAYS',
            'GDPR_DSAR_MAX_ACTIVE_REQUESTS_PER_TYPE',
            'GDPR_DSAR_EXPORT_STORAGE_RELATIVE',
            'GDPR_DSAR_SEND_CUSTOMER_EMAILS',
            'GDPR_DSAR_NOTIFY_ADMIN_NEW_REQUEST',
        ]);

        // Keep data tables by default to preserve compliance records.
        parent::executeUninstall();
        return true;
    }

    private function ensureTableConstants(): void
    {
        foreach (self::TABLE_MAP as $constant => $table) {
            if (!defined($constant)) {
                define($constant, DB_PREFIX . $table);
            }
        }
    }
}
