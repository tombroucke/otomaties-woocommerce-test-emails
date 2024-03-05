<?php
/**
 * Plugin Name:     Otomaties Woocommerce Test Emails
 * Description:     Preview WooCommerce Emails through WooCommerce->Settings->Emails
 * Author:          Tom Broucke
 * Author URI:      https://tombroucke.be
 * Text Domain:     woocommerce-test-emails
 * License:         GPLv2 or later
 * License URI:     http://www.opensource.org/licenses/gpl-license.php
 * Domain Path:     /languages
 * Version:           1.4.0
 *
 * @package         Woocommerce_Test_Emails
 */

namespace Otomaties\WcTestEmails;

use Pelago\Emogrifier\CssInliner;
use Pelago\Emogrifier\HtmlProcessor\CssToAttributeConverter;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;

if (! defined('ABSPATH')) {
    exit;
}

class WcTestEmails
{
    
    private static $instance = null;

    /**
     * Creates or returns an instance of this class.
     *
     * @since  1.0.0
     * @return WcTestEmails A single instance of this class.
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize
     */
    public function __construct()
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        add_action('admin_init', [$this, 'previewEmail']);
        add_filter('woocommerce_email_setting_columns', [$this, 'settingsPreviewColumn']);
        add_action('woocommerce_email_setting_column_preview', [$this, 'settingsPreviewButton'], 10);
    }

    public function previewEmail()
    {
        $action = sanitize_text_field($_GET['action'] ?? null);
        $lang = sanitize_text_field($_GET['lang'] ?? null);

        if ('preview_email' == $action) {
            global $sitepress;
            if (isset($sitepress) && $lang) {
                $sitepress->switch_lang($lang);
            }

            $emailType = sanitize_text_field($_GET['type'] ?? null);
            $orderId    = sanitize_text_field($_GET['order'] ?? null);
            $order      = new \WC_Order($orderId);
            $user       = $order->get_user();
            $class      = $this->emailType($emailType);
            if ($class) {
                $email             = WC()->mailer()->emails[$class];
                $email->user_login = $user ? $user->get('user_login') : 'guest';
                $email->object     = $order;
                $content           = $email->get_content_html();
                if ('html' == $email->email_type) {
                    $content = $this->styleInline($content);
                }
                echo $content;
            } else {
                throw new \Exception(sprintf('Email type %s doesn\'t exist', $emailType), 1);
            }
            die();
        }
    }

    private function emailType($emailType)
    {
        $emailTypes = $this->emailTypes();
        return isset($emailTypes[$emailType]) ? $emailTypes[$emailType] : null;
    }

    private function emailTypes()
    {
        return apply_filters('woocommerce_test_emails_email_types', [
            'cancelled_order'                   => 'WC_Email_Cancelled_Order',
            'customer_completed_order'          => 'WC_Email_Customer_Completed_Order',
            'customer_invoice'                  => 'WC_Email_Customer_Invoice',
            'customer_new_account'              => 'WC_Email_Customer_New_Account',
            'customer_note'                     => 'WC_Email_Customer_Note',
            'customer_on_hold_order'            => 'WC_Email_Customer_On_Hold_Order',
            'customer_processing_order'         => 'WC_Email_Customer_Processing_Order',
            'customer_refunded_order'           => 'WC_Email_Customer_Refunded_Order',
            'customer_reset_password'           => 'WC_Email_Customer_Reset_Password',
            'failed_order'                      => 'WC_Email_Failed_Order',
            'new_order'                         => 'WC_Email_New_Order',
            'cancelled_subscription'            => 'WCS_Email_Cancelled_Subscription',
            'customer_completed_renewal_order'  => 'WCS_Email_Completed_Renewal_Order',
            'customer_completed_switch_order'   => 'WCS_Email_Completed_Switch_Order',
            'customer_payment_retry'            => 'WCS_Email_Customer_Payment_Retry',
            'customer_processing_renewal_order' => 'WCS_Email_Processing_Renewal_Order',
            'customer_renewal_invoice'          => 'WCS_Email_Customer_Renewal_Invoice',
            'expired_subscription'              => 'WCS_Email_Expired_Subscription',
            'new_renewal_order'                 => 'WCS_Email_New_Renewal_Order',
            'new_switch_order'                  => 'WCS_Email_New_Switch_Order',
            'suspended_subscription'            => 'WCS_Email_On_Hold_Subscription',
            'expired_subscription'              => 'WCS_Email_Expired_Subscription',
            'payment_retry'                     => 'WCS_Email_Payment_Retry',
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
    public function styleInline($content)
    {
        ob_start();
        wc_get_template('emails/email-styles.php');
        $css = apply_filters('woocommerce_email_styles', ob_get_clean(), $this);


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
            $content = '<style type="text/css">' . $css . '</style>' . $content;
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
    protected function supportsEmogrifier()
    {
        return class_exists('DOMDocument');
    }

    public function settingsPreviewColumn($columns)
    {
        $columns = array_slice($columns, 0, 4, true) +
        ['preview' => __('Preview', 'woocommerce-test-emails')] +
        array_slice($columns, 4, count($columns) - 4, true);

        return $columns;
    }

    public function settingsPreviewButton($email)
    {
        $orders = wc_get_orders([]);
        $output = __('Preview will be available once an order has been placed.', 'woocommerce-test-emails');
        if (!empty($orders)) {
            $order = $orders[0];

            $requestUri = sanitize_text_field($_SERVER['REQUEST_URI'] ?? null);
            $httpHost = sanitize_text_field($_SERVER['HTTP_HOST'] ?? null);
            $currentUrl = sprintf('%s://%s', ( isset($_SERVER['HTTPS']) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ), $httpHost . $requestUri);
            $targetUrl  = add_query_arg([
                'action' => 'preview_email',
                'type'   => $email->id,
                'order'  => $order->get_ID(),
            ], $currentUrl);
            $output = sprintf('<a class="button button-secondary" href="%s" target="_blank">%s</a>', $targetUrl, __('Preview e-mail', 'woocommerce-test-emails'));
        }
        printf('<td>%s</td>', $output);
    }
}

add_action('plugins_loaded', ['Otomaties\WcTestEmails\\WcTestEmails', 'instance']);
