<?php
/**
 * Plugin Name:       WooCommerce Multiplier Variation Pricing
 * Plugin URI:        https://yaroslav-kozak.com/ # Optional
 * Description:       Adjusts variation prices based on a custom multiplier field added to each variation.
 * Version:           1.1.0
 * Author:            Yaroslav Kozak
 * Author URI:         https://yaroslav-kozak.com/ # Optional
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woocommerce-multiplier-variation-pricing
 * Domain Path:       /languages # Optional
 * WC requires at least: 3.5 # Updated minimum reasonable WC version
 * WC tested up to:   8.0 # Adjust as per current WC version
 * Requires PHP:      7.2 # Minimum recommended PHP version
 * Declare HPOS Compatibility: Compatible
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Declare High-Performance Order Storage (HPOS) compatibility.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Initialize the plugin functionality once all plugins are loaded.
 */
function wcmvp_init_plugin() {
    // Stop initialization if WooCommerce is not active.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'wcmvp_wc_not_active_notice' );
        return;
    }

    // Load the main plugin class.
    WCMVP_Pricing::get_instance();
}
add_action( 'plugins_loaded', 'wcmvp_init_plugin' );

/**
 * Display an admin notice if WooCommerce is not active.
 */
function wcmvp_wc_not_active_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'WooCommerce Multiplier Variation Pricing requires WooCommerce to be activated.', 'woocommerce-multiplier-variation-pricing' ); ?></p>
    </div>
    <?php
}


/**
 * Main plugin class WCMVP_Pricing.
 * Follows Singleton pattern to prevent multiple instances.
 */
final class WCMVP_Pricing {

    /** @var WCMVP_Pricing|null Class instance. */
    private static $instance = null;

    /** Plugin version. */
    const VERSION = '1.1.2';

    /** Meta key for storing the multiplier. */
    const META_KEY_MULTIPLIER = '_wcmvp_multiplier'; // Prefixed meta key

    /** Input field name base for the multiplier setting. */
    const FIELD_NAME_MULTIPLIER = '_wcmvp_multiplier_field'; // Prefixed field name

    /** Nonce action name for saving the multiplier. */
    const NONCE_ACTION = 'wcmvp_save_multiplier';

    /** Nonce field name. */
    const NONCE_NAME = '_wcmvp_multiplier_nonce';

    /**
     * Get the single instance of the class.
     *
     * @return WCMVP_Pricing
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * Sets up hooks.
     */
    private function __construct() {
        $this->add_hooks();
    }

    /**
     * Add WordPress and WooCommerce hooks.
     */
    private function add_hooks() {
        // --- Admin Hooks ---
        // Add multiplier field to variation settings UI.
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_multiplier_field_html' ), 10, 3 );
        // Save multiplier value from admin.
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_multiplier_field_value' ), 10, 2 );

        // --- Core Price Calculation Hooks ---
        // Filter core price retrieval (used by cart, backend calculations). Priority 100 to run late.
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'adjust_variation_price_core' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'adjust_variation_price_core' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'adjust_variation_price_core' ), 100, 2 );

        // Filter the raw price arrays *before* WC syncs min/max prices (for price range). Priority 100.
        add_filter( 'woocommerce_variation_prices', array( $this, 'filter_variation_prices_array' ), 100, 3 );

        // --- Frontend Display & Caching Hooks ---
        // Filter variation data used by frontend JavaScript (dynamic price display). Priority 100.
        add_filter( 'woocommerce_available_variation', array( $this, 'adjust_variation_data_for_js' ), 100, 3 );
        // Filter price hash to ensure correct cache invalidation.
        add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'add_multiplier_to_price_hash' ), 10, 3 );
        // Enqueue frontend script for flicker fix.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
    }

    /**
     * Output the HTML for the multiplier input field in variation settings.
     */
    public function add_multiplier_field_html( $loop, $variation_data, $variation ) {
        $multiplier_value = get_post_meta( $variation->ID, self::META_KEY_MULTIPLIER, true );
        $multiplier_value = ( $multiplier_value === '' || $multiplier_value === null ) ? '1' : $multiplier_value;

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME . '_' . $loop );

        woocommerce_wp_text_input( array(
            'id'                => self::FIELD_NAME_MULTIPLIER . '_' . $loop,
            'name'              => self::FIELD_NAME_MULTIPLIER . '[' . $loop . ']',
            'value'             => esc_attr( $multiplier_value ),
            'label'             => esc_html__( 'Price Multiplier', 'woocommerce-multiplier-variation-pricing' ),
            'desc_tip'          => true,
            'description'       => esc_html__( 'Final price = Base Price * Multiplier. Use 1 for no change.', 'woocommerce-multiplier-variation-pricing' ),
            'type'              => 'number',
            'custom_attributes' => array( 'step' => 'any', 'min'  => '0' ),
            'wrapper_class'     => 'form-row form-row-full',
            'data_type'         => 'decimal',
        ) );
    }

    /**
     * Save the multiplier value when the variation is saved. Includes nonce verification.
     */
    public function save_multiplier_field_value( $variation_id, $i ) {
        $nonce_name = self::NONCE_NAME . '_' . $i;
        if ( ! isset( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ $nonce_name ] ), self::NONCE_ACTION ) ) {
            // Check if field is present even if nonce failed (might indicate non-UI save attempt)
            if ( isset( $_POST[ self::FIELD_NAME_MULTIPLIER ] ) && isset( $_POST[ self::FIELD_NAME_MULTIPLIER ][ $i ] ) ) {
                // Log or handle potential CSRF attempt / non-standard save if needed
                // error_log("WCMVP Warning: Nonce check failed or missing for variation ID {$variation_id} during save, but field value present.");
            }
            return; // Strict: Stop if nonce check fails
        }

        $multiplier_value = '1'; // Default value.
        $field_key        = self::FIELD_NAME_MULTIPLIER;

        if ( isset( $_POST[ $field_key ] ) && isset( $_POST[ $field_key ][ $i ] ) ) {
            $submitted_value = sanitize_text_field( wp_unslash( $_POST[ $field_key ][ $i ] ) );
            if ( is_numeric( $submitted_value ) && floatval( $submitted_value ) >= 0 ) {
                $multiplier_value = wc_format_decimal( $submitted_value );
            } else {
                $multiplier_value = '1';
            }
        }

        update_post_meta( $variation_id, self::META_KEY_MULTIPLIER, $multiplier_value );
    }

    /**
     * Helper function to get the validated multiplier for a variation.
     */
    private function _get_validated_multiplier( $variation_id ) {
        $multiplier_meta = get_post_meta( $variation_id, self::META_KEY_MULTIPLIER, true );
        if ( $multiplier_meta === '' || $multiplier_meta === null || ! is_numeric( $multiplier_meta ) || floatval( $multiplier_meta ) < 0 ) {
            return 1.0;
        }
        return floatval( $multiplier_meta );
    }

    /**
     * Helper function to calculate the multiplied price.
     */
    private function _calculate_multiplied_price( $price, $multiplier ) {
        if ( $price === '' || $price === null || ! is_numeric( $price ) ) {
            return null;
        }
        // Use tolerance for float comparison
        if ( abs( $multiplier - 1.0 ) < 0.00001 ) {
             return (float) $price;
        }
        return (float) $price * $multiplier;
    }

    /**
     * Filter core variation prices (regular, sale, main price). Returns raw calculated numeric value.
     */
    public function adjust_variation_price_core( $price, $variation ) {
        $multiplier       = $this->_get_validated_multiplier( $variation->get_id() );
        $calculated_price = $this->_calculate_multiplied_price( $price, $multiplier );
        return ( $calculated_price !== null ) ? $calculated_price : $price;
    }

    /**
     * Filter the array of variation prices before WC syncs min/max prices.
     */
    public function filter_variation_prices_array( $prices, $product, $for_display ) {
        if ( ! $product->is_type('variable') || empty( $prices['price'] ) ) {
            return $prices;
        }

        foreach ( $prices as $price_type => $variation_prices ) {
            if ( ! is_array( $variation_prices ) ) continue;

            foreach ( $variation_prices as $variation_id => $price ) {
                $multiplier = $this->_get_validated_multiplier( $variation_id );
                if ( abs( $multiplier - 1.0 ) < 0.00001 ) continue;

                $calculated_price = $this->_calculate_multiplied_price( $price, $multiplier );
                if ( $calculated_price !== null ) {
                    $prices[ $price_type ][ $variation_id ] = $calculated_price;
                }
            }
        }
        return $prices;
    }

    /**
     * Filter the variation data array used by frontend JavaScript. Explicitly sets display prices and price_html.
     */
    public function adjust_variation_data_for_js( $variation_data, $product, $variation ) {
        $variation_id = $variation->get_id();
        $multiplier   = $this->_get_validated_multiplier( $variation_id );

        if ( abs( $multiplier - 1.0 ) < 0.00001 ) {
            return $variation_data;
        }

        $regular_price_base = $variation->get_regular_price( 'edit' );
        $sale_price_base    = $variation->get_sale_price( 'edit' );

        $new_regular_price = $this->_calculate_multiplied_price( $regular_price_base, $multiplier );
        $new_sale_price    = $this->_calculate_multiplied_price( $sale_price_base, $multiplier );

        $variation_data['display_regular_price'] = ( $new_regular_price !== null ) ? wc_format_decimal( $new_regular_price, wc_get_price_decimals() ) : '';

        $active_price = null;
        if ( $new_sale_price !== null && $new_regular_price !== null && $new_sale_price < $new_regular_price ) {
            $active_price = $new_sale_price;
        } elseif ( $new_sale_price !== null && $new_regular_price === null && ! empty( $sale_price_base ) ) {
            $active_price = $new_sale_price;
        } elseif ( $new_regular_price !== null ) {
             $active_price = $new_regular_price;
        }

        $variation_data['display_price'] = ( $active_price !== null ) ? wc_format_decimal( $active_price, wc_get_price_decimals() ) : '';

        $price_html = '';
        if ( $active_price !== null ) {
            $display_active_price  = wc_get_price_to_display( $variation, array( 'price' => $active_price ) );
            $display_regular_price = ( $new_regular_price !== null ) ? wc_get_price_to_display( $variation, array( 'price' => $new_regular_price ) ) : null;

            if ( $new_sale_price !== null && $display_regular_price !== null && $new_sale_price < $new_regular_price ) {
                 $price_html = wc_format_sale_price(
                     wc_price( $display_regular_price ),
                     wc_price( $display_active_price )
                 );
            } else {
                 $price_html = wc_price( $display_active_price );
            }
            if ( ! empty( $price_html ) ) {
                 $price_html .= $variation->get_price_suffix();
            }
        } else {
             $price_html = $variation_data['price_html'];
        }
        $variation_data['price_html'] = $price_html;

        return $variation_data;
    }

    /**
     * Add multiplier data to the variation prices hash for cache invalidation.
     */
    public function add_multiplier_to_price_hash( $hash, $product, $display ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            $variation_ids = $product->get_children();
            if ( ! empty( $variation_ids ) ) {
                $multiplier_values = array();
                foreach ( $variation_ids as $variation_id ) {
                    if ( $variation_id > 0 ) {
                        $multiplier = $this->_get_validated_multiplier( $variation_id );
                        $multiplier_values[ $variation_id ] = (string) $multiplier;
                    }
                }
                if ( ! empty( $multiplier_values ) ) {
                    $hash['wcmvp_multipliers'] = $multiplier_values;
                }
            }
        }
        return $hash;
    }

    /**
     * Enqueue frontend scripts and add inline script for flicker fix.
     */
    public function enqueue_frontend_scripts() {
        if ( ! is_product() || ! function_exists( 'wc_get_product' ) ) return;
        $product_id = get_queried_object_id();
        if ( ! $product_id ) return; // Ensure we have a product ID
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) return;

        $script = "
            document.addEventListener('DOMContentLoaded', function() {
                var variationForms = document.querySelectorAll('form.variations_form[data-product_id=\"". esc_js( $product_id ) ."\"]');
                variationForms.forEach(function(form) {
                    var priceElement = form.querySelector('.woocommerce-variation-price');
                    if (priceElement) {
                        priceElement.innerHTML = ' '; // Clear initial price display
                    }
                });
            });
        ";
        // Ensure WC variation script handle exists before adding inline script
        if ( wp_script_is( 'wc-add-to-cart-variation', 'registered' ) ) {
            wp_add_inline_script( 'wc-add-to-cart-variation', $script );
        } else {
            // Fallback: Add script in the footer if WC script isn't registered (less ideal)
            add_action('wp_footer', function() use ($script) {
                echo '<script type="text/javascript">' . $script . '</script>';
            }, 99); // High priority footer hook
        }

    }

} // End Class WCMVP_Pricing