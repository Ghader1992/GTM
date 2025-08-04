<?php
/**
 * Plugin Name:       WooCommerce Google Tag Manager Data Layer
 * Plugin URI:        https://github.com/jules-agent/woocommerce-gtm-datalayer
 * Description:       A comprehensive plugin to integrate Google Tag Manager with WooCommerce, providing a GA4-compliant data layer for all standard e-commerce events.
 * Version:           1.0.0
 * Author:            Jules
 * Author URI:        https://github.com/jules-agent
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-gtm-datalayer
 * WC requires at least: 3.0
 * WC tested up to: 8.4
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Checks for WooCommerce dependency and initializes all hooks.
 */
function wc_gtm_datalayer_init() {
    // Check if WooCommerce is active. If not, do nothing.
	if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'WooCommerce GTM Data Layer plugin requires WooCommerce to be installed and active.', 'wc-gtm-datalayer' );
            echo '</p></div>';
        });
		return;
	}

    // Instantiate the main plugin class.
	WC_GTM_DataLayer::get_instance();
}
add_action( 'plugins_loaded', 'wc_gtm_datalayer_init' );


/**
 * Final class WC_GTM_DataLayer.
 *
 * Encapsulates all plugin functionality to prevent conflicts.
 * Follows the Singleton pattern to ensure only one instance exists.
 */
final class WC_GTM_DataLayer {

    /**
     * @var WC_GTM_DataLayer The single instance of the class
     */
    private static $instance;

    /**
     * @var array Holds the data layer variables.
     */
    private $data_layer = [];

    /**
     * @var string|null GTM Container ID.
     */
    private $gtm_id = null;


    /**
     * Gets the single instance of the class.
     *
     * @return WC_GTM_DataLayer
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
	 * Private constructor to prevent direct instantiation.
	 * Sets up hooks and filters.
	 */
    private function __construct() {
        $this->gtm_id = trim( get_option( 'wc_gtm_datalayer_gtm_id' ) );

        // Register admin settings
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );

        // Only add tracking hooks if GTM ID is provided
        if ( ! empty( $this->gtm_id ) ) {
            // Start PHP session for add_to_cart event tracking
            add_action( 'init', [ $this, 'start_session' ] );

            // Add GTM container script to head
            add_action( 'wp_head', [ $this, 'inject_gtm_container' ], 1 );

            // General and e-commerce event tracking hooks
            add_action( 'wp_head', [ $this, 'push_general_data' ], 10 );
            add_action( 'wp_head', [ $this, 'push_ecommerce_events' ], 10 );

            // Hook for add_to_cart event
            add_action( 'woocommerce_add_to_cart', [ $this, 'track_add_to_cart' ], 10, 6 );

            // Hook for purchase event on the thank you page
            add_action( 'woocommerce_thankyou', [ $this, 'track_purchase' ], 10, 1 );

            // Add an empty script to attach our data layer to
            add_action('wp_enqueue_scripts', function() {
                wp_register_script('wc-gtm-datalayer-helper', false, [], null, true);
                wp_enqueue_script('wc-gtm-datalayer-helper');
            });

            // Output the data layer script
            add_action( 'wp_print_footer_scripts', [ $this, 'print_data_layer_script' ], 20 );
        }
    }

    /**
     * Start PHP session if not already started.
     * Required for tracking add_to_cart events across page loads.
     */
    public function start_session() {
        if ( ! session_id() && ! headers_sent() ) {
            session_start();
        }
    }

    /**
     * =================================================================
     * ADMIN SETTINGS
     * =================================================================
     */

    /**
     * Add the settings page to the WordPress admin menu.
     */
    public function add_settings_page() {
        add_options_page(
            __( 'GTM Data Layer', 'wc-gtm-datalayer' ),
            __( 'GTM Data Layer', 'wc-gtm-datalayer' ),
            'manage_options',
            'wc-gtm-datalayer-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Render the HTML for the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'wc_gtm_datalayer_options' );
                do_settings_sections( 'wc-gtm-datalayer-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register settings, section, and field with the Settings API.
     */
    public function register_settings() {
        register_setting(
            'wc_gtm_datalayer_options',
            'wc_gtm_datalayer_gtm_id',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        add_settings_section(
            'wc_gtm_datalayer_section',
            __( 'GTM Settings', 'wc-gtm-datalayer' ),
            null,
            'wc-gtm-datalayer-settings'
        );

        add_settings_field(
            'wc_gtm_datalayer_gtm_id',
            __( 'GTM Container ID', 'wc-gtm-datalayer' ),
            [ $this, 'render_gtm_id_field' ],
            'wc-gtm-datalayer-settings',
            'wc_gtm_datalayer_section'
        );
    }

    /**
     * Render the GTM Container ID input field.
     */
    public function render_gtm_id_field() {
        $gtm_id = get_option( 'wc_gtm_datalayer_gtm_id', '' );
        echo '<input type="text" name="wc_gtm_datalayer_gtm_id" value="' . esc_attr( $gtm_id ) . '" size="40" placeholder="GTM-XXXXXXX">';
        echo '<p class="description">' . esc_html__( 'Enter your Google Tag Manager container ID.', 'wc-gtm-datalayer' ) . '</p>';
    }

    /**
     * =================================================================
     * GTM CONTAINER SCRIPT INJECTION
     * =================================================================
     */

    /**
     * Injects the GTM container script into the <head> of the site.
     */
    public function inject_gtm_container() {
        ?>
        <!-- Google Tag Manager by WC GTM DataLayer -->
        <script>
            window.dataLayer = window.dataLayer || [];
        </script>
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?php echo esc_js( $this->gtm_id ); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php
    }

    /**
     * =================================================================
     * DATA LAYER PUSH LOGIC
     * =================================================================
     */

    /**
     * Pushes general page view data to the data layer on every page load.
     */
    public function push_general_data() {
        $user = wp_get_current_user();
        $user_id = $user->exists() ? $user->ID : null;

        $general_data = [
            'event'    => 'page_view',
            'pageType' => $this->get_page_type(),
            'userId'   => $user_id,
            'language' => get_bloginfo( 'language' ),
            'currency' => get_woocommerce_currency(),
        ];

        $this->add_to_data_layer( $general_data );
    }

    /**
     * Pushes e-commerce event data based on the current page context.
     * This handles view_item_list, view_item, begin_checkout, and the session-based add_to_cart.
     */
    public function push_ecommerce_events() {
        // Handle add_to_cart event from session
        if ( isset( $_SESSION['wc_gtm_add_to_cart_event'] ) && is_array( $_SESSION['wc_gtm_add_to_cart_event'] ) ) {
            $this->add_to_data_layer( $_SESSION['wc_gtm_add_to_cart_event'] );
            unset( $_SESSION['wc_gtm_add_to_cart_event'] );
        }

        // Product list view
        if ( is_shop() || is_product_category() || is_product_tag() ) {
            $this->track_view_item_list();
        }
        // Single product view
        elseif ( is_product() ) {
            $this->track_view_item();
        }
        // Checkout page
        elseif ( is_checkout() && ! is_order_received_page() ) {
            $this->track_begin_checkout();
        }
    }

    /**
     * Tracks the 'add_to_cart' event by storing item data in the session.
     * The data is then pushed to the data layer on the next page load.
     */
    public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $variation_id ? $variation_id : $product_id );
        if ( ! $product ) {
            return;
        }

        $items = [ $this->get_product_data( $product, $quantity ) ];

        $event_data = [
            'event' => 'add_to_cart',
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $product->get_price() * $quantity,
                'items'    => $items,
            ]
        ];

        $_SESSION['wc_gtm_add_to_cart_event'] = $event_data;
    }

    /**
     * Tracks the 'purchase' event on the thank you page.
     * Uses order meta to prevent duplicate tracking on page reload.
     */
    public function track_purchase( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Prevent duplicate tracking
        if ( $order->get_meta( '_wc_gtm_purchase_tracked' ) ) {
            return;
        }

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = $this->get_product_data( $product, $item->get_quantity(), $order_id );
        }

        $purchase_data = [
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => $order->get_order_number(),
                'affiliation'    => get_bloginfo( 'name' ),
                'value'          => (float) $order->get_total(),
                'tax'            => (float) $order->get_total_tax(),
                'shipping'       => (float) $order->get_shipping_total(),
                'currency'       => $order->get_currency(),
                'items'          => $items,
            ]
        ];

        $this->add_to_data_layer( $purchase_data );

        // Mark order as tracked
        $order->update_meta_data( '_wc_gtm_purchase_tracked', true );
        $order->save();
    }

    /**
     * Tracks the 'view_item_list' event for product archive pages.
     */
    private function track_view_item_list() {
        global $wp_query;
        $items = [];
        $i = 0;
        if ( have_posts() ) {
            while ( have_posts() ) {
                the_post();
                $product = wc_get_product( get_the_ID() );
                if ( $product ) {
                    $items[] = $this->get_product_data( $product, 1, null, $i++ );
                }
            }
            wp_reset_postdata();
        }

        if ( ! empty( $items ) ) {
            $this->add_to_data_layer( [
                'event' => 'view_item_list',
                'ecommerce' => [
                    'items' => $items,
                ]
            ] );
        }
    }

    /**
     * Tracks the 'view_item' event for single product pages.
     */
    private function track_view_item() {
        global $product;
        if ( ! is_a( $product, 'WC_Product' ) ) {
           $product = wc_get_product(get_the_ID());
        }

        if ( !$product ) {
            return;
        }

        $items = [ $this->get_product_data( $product ) ];

        $this->add_to_data_layer( [
            'event' => 'view_item',
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $product->get_price(),
                'items'    => $items,
            ]
        ] );
    }

    /**
     * Tracks the 'begin_checkout' event on the checkout page.
     */
    private function track_begin_checkout() {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return;
        }

        $items = [];
        $i = 0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            $items[] = $this->get_product_data( $product, $cart_item['quantity'], null, $i++ );
        }

        if ( ! empty( $items ) ) {
            $this->add_to_data_layer( [
                'event' => 'begin_checkout',
                'ecommerce' => [
                    'currency' => get_woocommerce_currency(),
                    'value'    => (float) $cart->get_totals()['total'],
                    'items'    => $items,
                ]
            ] );
        }
    }

    /**
     * =================================================================
     * UTILITY AND HELPER FUNCTIONS
     * =================================================================
     */

    /**
     * Helper function to determine the type of the current page.
     *
     * @return string Page type.
     */
    private function get_page_type() {
        if ( is_front_page() ) return 'home';
        if ( is_shop() || is_product_category() || is_product_tag() ) return 'category';
        if ( is_product() ) return 'product';
        if ( is_cart() ) return 'cart';
        if ( is_checkout() && ! is_order_received_page() ) return 'checkout';
        if ( is_order_received_page() ) return 'purchase';
        return 'other';
    }

    /**
     * Formats a WC_Product object into a GA4-compliant data array.
     *
     * @param WC_Product $product    The WooCommerce product object.
     * @param int        $quantity   The quantity of the product.
     * @param int|null   $order_id   The order ID, if applicable.
     * @param int|null   $index      The list position index.
     * @return array                 Formatted product data.
     */
    private function get_product_data( $product, $quantity = 1, $order_id = null, $index = null ) {
        $item = [
            'item_id'   => (string) $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
            'item_name' => $product->get_name(),
            'price'     => (float) $product->get_price(),
        ];

        // Add quantity if it's more than 1 or if it's explicitly provided
        if ( $quantity > 0 ) {
            $item['quantity'] = (int) $quantity;
        }

        // Get categories
        $category_ids = $product->get_category_ids();
        if ( ! empty( $category_ids ) ) {
            $categories = [];
            $term_count = 0;
            foreach ( $category_ids as $category_id ) {
                $term = get_term( $category_id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    // GA4 supports up to 5 item-scoped categories
                    if ( $term_count >= 5) break;
                    $key = ($term_count == 0) ? 'item_category' : 'item_category' . ($term_count + 1);
                    $item[$key] = $term->name;
                    $term_count++;
                }
            }
        }

        // Add list position index if provided
        if ( $index !== null ) {
            $item['index'] = (int) $index;
        }

        return $item;
    }

    /**
     * Adds an event object to the internal data layer array.
     *
     * @param array $data The data to push to the data layer.
     */
    private function add_to_data_layer( $data ) {
        $this->data_layer[] = $data;
    }

    /**
     * Prints the data layer script in the footer.
     * Uses wp_add_inline_script for safety and compatibility.
     */
    public function print_data_layer_script() {
        if ( empty( $this->data_layer ) ) {
            return;
        }

        $script = '';
        foreach( $this->data_layer as $data ) {
             $script .= "window.dataLayer.push(" . wp_json_encode( $data ) . ");\n";
        }

        if ( ! empty( $script ) ) {
            wp_add_inline_script( 'wc-gtm-datalayer-helper', $script, 'before' );
        }
    }
}
