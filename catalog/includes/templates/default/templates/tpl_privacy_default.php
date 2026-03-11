<?php
/**
 * Privacy template override to expose DSAR entrypoint without core edits.
 */
?>
<div class="centerColumn" id="privacy">
<h1 id="privacyDefaultHeading"><?php echo HEADING_TITLE; ?></h1>

<?php if (DEFINE_PRIVACY_STATUS >= 1 and DEFINE_PRIVACY_STATUS <= 2) { ?>
<div id="privacyDefaultMainContent" class="content">
<?php
  require($define_page);
?>
</div>
<?php } ?>

<?php if (defined('FILENAME_GDPR_DSAR') && zen_is_logged_in()) { ?>
<div class="content" id="privacyDsarLink">
  <p><a href="<?php echo zen_href_link(FILENAME_GDPR_DSAR, '', 'SSL'); ?>"><?php echo defined('TEXT_PRIVACY_REQUEST_LINK') ? TEXT_PRIVACY_REQUEST_LINK : 'Manage privacy data requests'; ?></a></p>
</div>
<?php } ?>

<div class="buttonRow back"><?php echo zen_back_link() . zen_image_button(BUTTON_IMAGE_BACK, BUTTON_BACK_ALT) . '</a>'; ?></div>
</div>
