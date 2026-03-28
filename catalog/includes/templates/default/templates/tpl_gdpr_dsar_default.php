<?php
/**
 * GDPR/DSAR page template
 */
?>
<div class="centerColumn" id="gdprDsarDefault">
    <h1 id="gdprDsarHeading"><?php echo HEADING_TITLE; ?></h1>

    <?php if ($messageStack->size('gdpr_dsar') > 0) echo $messageStack->output('gdpr_dsar'); ?>

    <?php if (!empty($gdprActivePolicyVersion) && !empty($gdprNeedsPolicyAcceptance)): ?>
        <div class="content" id="gdprPolicyVersionInfo" style="border: 2px solid #c27c00; background: #fff8e1; padding: 1rem; margin: 1rem 0 1.5rem;">
            <h2 style="margin-top: 0; color: #8a5a00;"><?php echo TEXT_ACCEPT_CURRENT_POLICY; ?></h2>
            <p><strong><?php echo sprintf(TEXT_POLICY_ACTIVE_VERSION, zen_output_string_protected($gdprActivePolicyVersion)); ?></strong></p>
            <p>
                <?php
                echo sprintf(
                    TEXT_POLICY_ACCEPTANCE_PROMPT,
                    '<a href="' . zen_output_string_protected($gdprPrivacyPolicyLink) . '" target="_blank" rel="noopener noreferrer">' . TEXT_PRIVACY_POLICY_LINK . '</a>'
                );
                ?>
            </p>
            <form method="post" action="<?php echo zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'); ?>" style="margin-bottom: 0;">
                <?php echo zen_draw_hidden_field('action', 'accept_policy'); ?>
                <?php echo zen_draw_hidden_field('securityToken', $_SESSION['securityToken'] ?? ''); ?>
                <?php echo zen_draw_hidden_field('accept_policy', '1'); ?>
                <button type="submit" style="background: #c27c00; border: 1px solid #8a5a00; color: #fff; font-size: 1rem; font-weight: bold; padding: 0.8rem 1.4rem; cursor: pointer;">
                    <?php echo TEXT_ACCEPT_CURRENT_POLICY; ?>
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="content" id="gdprDsarIntro">
        <p><?php echo TEXT_INTRO; ?></p>
        <p><strong><?php echo sprintf(TEXT_EXPORT_RETENTION_NOTICE, (int)$gdprExportExpiryDays); ?></strong></p>
    </div>

    <div class="content" id="gdprDsarErasureWarning" style="border: 2px solid #b22222; background: #fff1f1; padding: 1rem; margin: 1rem 0;">
        <h2 style="margin-top: 0; color: #8b0000;"><?php echo TEXT_ERASURE_WARNING_HEADING; ?></h2>
        <p><strong><?php echo TEXT_ERASURE_WARNING_BODY; ?></strong></p>
        <p style="margin-bottom: 0;"><?php echo TEXT_ERASURE_WARNING_DETAIL; ?></p>
    </div>

    <?php if (!empty($gdprActivePolicyVersion)): ?>
        <div class="content" id="gdprPolicyVersionInfo">
            <p><?php echo sprintf(TEXT_POLICY_ACTIVE_VERSION, zen_output_string_protected($gdprActivePolicyVersion)); ?></p>
            <?php if (!empty($gdprNeedsPolicyAcceptance)): ?>
                <p>
                    <?php
                    echo sprintf(
                        TEXT_POLICY_ACCEPTANCE_REQUIRED,
                        '<a href="' . zen_output_string_protected($gdprPrivacyPolicyLink) . '" target="_blank" rel="noopener noreferrer">' . TEXT_PRIVACY_POLICY_LINK . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'); ?>">
        <?php echo zen_draw_hidden_field('action', 'submit'); ?>
        <?php echo zen_draw_hidden_field('securityToken', $_SESSION['securityToken'] ?? ''); ?>
        <fieldset>
            <legend><?php echo TEXT_REQUEST_TYPE; ?></legend>
            <label>
                <input type="radio" name="request_type" value="export" checked>
                <?php echo TEXT_TYPE_EXPORT; ?>
            </label><br>
            <label>
                <input type="radio" name="request_type" value="erasure">
                <?php echo TEXT_TYPE_ERASURE; ?>
            </label>
            <div style="margin: 0.5rem 0 0 1.5rem; color: #8b0000; font-weight: bold;">
                <?php echo TEXT_TYPE_ERASURE_NOTICE; ?>
            </div>
        </fieldset>

        <p>
            <label for="request_notes"><?php echo TEXT_REQUEST_NOTES; ?></label><br>
            <textarea id="request_notes" name="request_notes" rows="4" cols="60"></textarea>
        </p>

        <p>
            <button type="submit" name="submit_request" value="1"><?php echo TEXT_SUBMIT_REQUEST; ?></button>
        </p>
    </form>

    <h2><?php echo TEXT_HISTORY_HEADING; ?></h2>
    <table id="gdprDsarHistory" class="table">
        <tr class="tableHeading">
            <th><?php echo TEXT_TABLE_ID; ?></th>
            <th><?php echo TEXT_TABLE_TYPE; ?></th>
            <th><?php echo TEXT_TABLE_STATUS; ?></th>
            <th><?php echo TEXT_TABLE_SUBMITTED; ?></th>
            <th><?php echo TEXT_TABLE_PROCESSED; ?></th>
            <th><?php echo TEXT_TABLE_DOWNLOAD; ?></th>
        </tr>
        <?php if (empty($dsarRequests)): ?>
            <tr><td colspan="6"><?php echo TEXT_NO_REQUESTS; ?></td></tr>
        <?php else: ?>
            <?php foreach ($dsarRequests as $request): ?>
                <tr>
                    <td><?php echo (int)$request['request_id']; ?></td>
                    <td><?php echo zen_output_string_protected($request['request_type']); ?></td>
                    <td><?php echo zen_output_string_protected($request['status']); ?></td>
                    <td><?php echo zen_output_string_protected($request['date_submitted']); ?></td>
                    <td><?php echo zen_output_string_protected($request['date_processed'] ?? ''); ?></td>
                    <td>
                        <?php if (!empty($request['download_available'])): ?>
                            <a href="<?php echo zen_href_link(FILENAME_GDPR_DSAR, 'action=download&token=' . urlencode($request['download_token']), 'SSL'); ?>"><?php echo TEXT_DOWNLOAD_EXPORT; ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <div class="buttonRow back"><?php echo zen_back_link() . zen_image_button(BUTTON_IMAGE_BACK, BUTTON_BACK_ALT) . '</a>'; ?></div>
</div>
