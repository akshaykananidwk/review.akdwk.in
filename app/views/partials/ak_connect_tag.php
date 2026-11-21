<?php
/**
 * AK Earn browser tag — include once, just before </body>, on public
 * pages where a referred visitor can land.
 *
 * The script is loaded from the master so it stays up to date without a
 * deploy here. Only the site key is exposed, which is public by design:
 * the SDK comment is explicit that no secret is ever used in the
 * browser, and every money event is sent server-side instead.
 *
 * Renders nothing when the connector is not configured, so a site
 * without the key behaves exactly as it did before.
 */
if (defined('AK_SITE_KEY') && AK_SITE_KEY !== '' && defined('AK_MASTER_URL') && AK_MASTER_URL !== ''):
?>
<script src="<?= htmlspecialchars(AK_MASTER_URL, ENT_QUOTES) ?>/connector/ak-connect.js"
        data-site-key="<?= htmlspecialchars(AK_SITE_KEY, ENT_QUOTES) ?>"
        defer></script>
<?php endif; ?>
