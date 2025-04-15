<?php
/**
 * Plugin Name:       Otomaties Woocommerce Preview Emails
 * Description:       Preview WooCommerce Emails through WooCommerce->Settings->Emails
 * Author:            Tom Broucke
 * Author URI:        https://tombroucke.be
 * Text Domain:       woocommerce-test-emails
 * License:           GPLv2 or later
 * License URI:       http://www.opensource.org/licenses/gpl-license.php
 * Domain Path:       /languages
 * Version:           2.0.0
 *
 * @package         Woocommerce_Preview_Emails
 */

namespace Otomaties\WcPreviewEmails;

use Pelago\Emogrifier\CssInliner;
use Pelago\Emogrifier\HtmlProcessor\CssToAttributeConverter;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Preview Emails
 */
class WcPreviewEmails
{
    /**
     * Add hooks
     *
     * @return void
     */
    public function addHooks() : void
    {
        add_action('admin_init', [$this, 'previewEmail']);
        add_filter('woocommerce_email_setting_columns', [$this, 'settingsPreviewColumn']);
        add_action('woocommerce_email_setting_column_preview', [$this, 'settingsPreviewButton'], 10);
    }

    /**
     * Show email preview
     *
     * @return void
     */
    public function previewEmail() : void
    {
        if ('preview_email' !== $_GET['action']) {
            return;
        }

        $lang       = sanitize_text_field($_GET['lang'] ?? null);
        $nonce      = sanitize_text_field($_GET['nonce'] ?? null);
        $emailType  = sanitize_text_field($_GET['type'] ?? null);
        $orderId    = sanitize_text_field($_GET['order'] ?? null);

        if (!wp_verify_nonce($nonce, 'preview_email_' . $emailType)) {
            exit('Invalid nonce');
        }

        $emailClass = $this->emailClass($emailType);

        if (!$emailClass) {
            exit(sprintf('Email type %s doesn\'t exist', $emailType));
        }

        $content = $this->getContent($emailClass, $orderId, $lang);
        if ($content) {
            exit($content);
        }
    }

    /**
     * Get email content
     *
     * @param string $emailClass
     * @param int $orderId
     * @param string|null $lang
     * @return string
     */
    private function getContent(string $emailClass, int $orderId, ?string $lang) : string
    {
        global $sitepress;
        if (isset($sitepress) && $lang) {
            $sitepress->switch_lang($lang);
        }

        $email          = WC()->mailer()->emails[$emailClass];
        $email->object  = wc_get_order($orderId);
        $htmlContent    = $email->get_content_html();
        if ('html' == $email->email_type) {
            $emailContent = $this->styleInline($htmlContent);
        }
        return $emailContent;

    }

    /**
     * Get email class name
     *
     * @param string $emailType
     * @return string|null
     */
    private function emailClass(string $emailType) : ?string
    {
        $emailTypes = $this->emailTypes();
        return $emailTypes[$emailType] ?? null;
    }

    /**
     * Get all email types
     *
     * @return array
     */
    private function emailTypes() : array
    {
        return apply_filters('woocommerce_test_emails_email_types', [
            'cancelled_order'                   => 'WC_Email_Cancelled_Order',
            'cancelled_subscription'            => 'WCS_Email_Cancelled_Subscription',
            'customer_completed_order'          => 'WC_Email_Customer_Completed_Order',
            'customer_completed_renewal_order'  => 'WCS_Email_Completed_Renewal_Order',
            'customer_completed_switch_order'   => 'WCS_Email_Completed_Switch_Order',
            'customer_invoice'                  => 'WC_Email_Customer_Invoice',
            'customer_failed_order'             => 'WC_Email_Customer_Failed_Order',
            'customer_new_account'              => 'WC_Email_Customer_New_Account',
            'customer_note'                     => 'WC_Email_Customer_Note',
            'customer_on_hold_order'            => 'WC_Email_Customer_On_Hold_Order',
            'customer_payment_retry'            => 'WCS_Email_Customer_Payment_Retry',
            'customer_processing_order'         => 'WC_Email_Customer_Processing_Order',
            'customer_processing_renewal_order' => 'WCS_Email_Processing_Renewal_Order',
            'customer_refunded_order'           => 'WC_Email_Customer_Refunded_Order',
            'customer_renewal_invoice'          => 'WCS_Email_Customer_Renewal_Invoice',
            'customer_reset_password'           => 'WC_Email_Customer_Reset_Password',
            'expired_subscription'              => 'WCS_Email_Expired_Subscription',
            'expired_subscription'              => 'WCS_Email_Expired_Subscription',
            'failed_order'                      => 'WC_Email_Failed_Order',
            'new_order'                         => 'WC_Email_New_Order',
            'new_renewal_order'                 => 'WCS_Email_New_Renewal_Order',
            'new_switch_order'                  => 'WCS_Email_New_Switch_Order',
            'payment_retry'                     => 'WCS_Email_Payment_Retry',
            'suspended_subscription'            => 'WCS_Email_On_Hold_Subscription',
        ]);
    }

    /**
     * Apply inline styles to dynamic content.
     *
     * We only inline CSS for html emails, and to do so we use Emogrifier library (if supported).
     *
     * @version 4.0.0
     * @param string|null $content Content that will receive inline styles.
     * @return string
     */
    private function styleInline(string $content) : string
    {
        ob_start();
        wc_get_template('emails/email-styles.php');
        $woocommerceEmailStyles = ob_get_clean();
        $css = apply_filters('woocommerce_email_styles', $woocommerceEmailStyles, $this);

        if ($this->supportsEmogrifier() && class_exists(CssInliner::class)) {
            try {
                $cssInliner = CssInliner::fromHtml($content)->inlineCss($css);

                do_action('woocommerce_emogrifier', $cssInliner, $this);

                $domDocument = $cssInliner->getDomDocument();

                HtmlPruner::fromDomDocument($domDocument)->removeElementsWithDisplayNone();
                $content = CssToAttributeConverter::fromDomDocument($domDocument)
                    ->convertCssToVisualAttributes()
                    ->render();
            } catch (\Exception $e) {
                $logger = wc_get_logger();
                $logger->error($e->getMessage(), ['source' => 'emogrifier']);
            }
        } else {
            $content = "<style type=\"text/css\">$css</style>$content";
        }

        return $content;
    }

    /**
     * Return if emogrifier library is supported.
     *
     * @version 4.0.0
     * @since 3.5.0
     * @return bool
     */
    protected function supportsEmogrifier() : bool
    {
        return class_exists('DOMDocument');
    }

    /**
     * Add preview column to email settings
     *
     * @param array $columns
     * @return array
     */
    public function settingsPreviewColumn(array $columns) : array
    {
        $columns = array_slice($columns, 0, 4, true) +
        ['preview' => __('Preview', 'woocommerce-test-emails')] +
        array_slice($columns, 4, count($columns) - 4, true);

        return $columns;
    }

    /**
     * Add preview button to email settings
     *
     * @param \WC_Email $email
     * @return void
     */
    public function settingsPreviewButton(\WC_Email $email) : void
    {
        $orders = wc_get_orders([
            'numberposts' => 1,
        ]);

        if (empty($orders)) {
            _e('Preview will be available once an order has been placed.', 'woocommerce-test-emails');
            return;
        }

        $order = $orders[0];

        $requestUri = sanitize_text_field($_SERVER['REQUEST_URI'] ?? null);
        $httpHost = sanitize_text_field($_SERVER['HTTP_HOST'] ?? null);
        $currentUrl = sprintf('%s://%s', ( isset($_SERVER['HTTPS']) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ), $httpHost . $requestUri);
        $nonce = wp_create_nonce('preview_email_' . $email->id);
        $targetUrl  = add_query_arg([
            'action' => 'preview_email',
            'type'   => $email->id,
            'order'  => $order->get_ID(),
            'nonce'  => $nonce,
        ], $currentUrl);
        $output = sprintf('<a class="button button-secondary" href="%s" target="_blank">%s</a>', $targetUrl, __('Preview', 'woocommerce-test-emails'));
        printf('<td>%s</td>', $output);
    }
}

add_action('woocommerce_init', function() {
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }

    (new WcPreviewEmails())->addHooks();
});
