<?php
/**
 * Plugin Name: Weather Info Widget
 * Description: A WordPress widget plugin to display current weather information for a specified city using the OpenWeather API, with a settings page to store and encrypt the API key (masked), styling options, and cache invalidation on key or city changes.
 * Version:     1.0
 * Author:      Muzammil Hussain
 * License:     GPL2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ------------------------------------------------------------------------
 * Section: Lightweight Settings Page & Encryption (Masked Password Field)
 * ------------------------------------------------------------------------
 */

/**
 * Register the settings page under "Settings → Weather Info API".
 */
function wiw_register_settings_page() {
    add_options_page(
        esc_html__( 'Weather API Settings', 'weather-info-widget' ),
        esc_html__( 'Weather Info API',   'weather-info-widget' ),
        'manage_options',
        'wiw-weather-api-settings',
        'wiw_render_settings_page'
    );
}
add_action( 'admin_menu', 'wiw_register_settings_page' );

/**
 * Render the settings page form and handle form submission.
 *
 * - Shows a password-type input (masked) instead of a plain text field.
 * - If submitted with a non-empty value, encrypt & save, then delete cached transients.
 * - If left empty, retain the existing encrypted key.
 */
function wiw_render_settings_page() {
    global $wpdb;

    if ( isset( $_POST['wiw_api_key_nonce'] ) && wp_verify_nonce( $_POST['wiw_api_key_nonce'], 'wiw_save_api_key' ) ) {
        // Sanitize the input (password field).
        $raw_key = sanitize_text_field( wp_unslash( $_POST['wiw_api_key'] ) );

        if ( ! empty( $raw_key ) ) {
            // Combine two WordPress salts to form a passphrase.
            $passphrase = SECURE_AUTH_KEY . NONCE_KEY;
            // Derive a 16-byte IV from the passphrase (using SHA-256).
            $iv = substr( hash( 'sha256', $passphrase, true ), 0, 16 );
            // Encrypt the raw API key using AES-256-CBC.
            $encrypted = openssl_encrypt( $raw_key, 'AES-256-CBC', $passphrase, 0, $iv );
            // Base64-encode for safe DB storage.
            $encrypted_base64 = base64_encode( $encrypted );
            // Save or update in options.
            update_option( 'wiw_encrypted_api_key', $encrypted_base64 );

            // Delete all transients related to cached weather data:
            // WordPress stores transients in options named '_transient_{key}' and '_transient_timeout_{key}'.
            // We remove both variants where key LIKE 'wiw_weather_data_%'.
            $like_pattern = $wpdb->esc_like( '_transient_wiw_weather_data_' ) . '%';
            // Delete transient values.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                    $like_pattern
                )
            );
            // Delete their timeouts.
            $timeout_pattern = $wpdb->esc_like( '_transient_timeout_wiw_weather_data_' ) . '%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                    $timeout_pattern
                )
            );

            echo '<div class="notice notice-success is-dismissible"><p>'
                 . esc_html__( 'API key saved, encrypted, and cache cleared.', 'weather-info-widget' )
                 . '</p></div>';
        } else {
            // Empty submission means “keep current key”.
            echo '<div class="notice notice-info is-dismissible"><p>'
                 . esc_html__( 'No new key entered; existing API key remains unchanged.', 'weather-info-widget' )
                 . '</p></div>';
        }
    }

    // Check if an encrypted key already exists (we do NOT decrypt or display it).
    $stored = get_option( 'wiw_encrypted_api_key', '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Weather API Settings', 'weather-info-widget' ); ?></h1>
        <form method="POST" action="">
            <?php wp_nonce_field( 'wiw_save_api_key', 'wiw_api_key_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wiw_api_key"><?php esc_html_e( 'OpenWeather API Key', 'weather-info-widget' ); ?></label>
                    </th>
                    <td>
                        <!--
                          Use a password field to mask the key.
                          If there is already a key stored, we leave this blank and show a placeholder.
                          On submit: if this field is non-empty, encrypt & save and clear cache.
                        -->
                        <input
                            name="wiw_api_key"
                            type="password"
                            id="wiw_api_key"
                            value=""
                            class="regular-text"
                            placeholder="<?php echo esc_attr( $stored ? str_repeat( '•', 16 ) : '' ); ?>"
                        >
                        <p class="description">
                            <?php
                            if ( $stored ) {
                                esc_html_e( 'Leave blank to keep existing key, or type a new one to replace it.', 'weather-info-widget' );
                            } else {
                                esc_html_e( 'Enter your OpenWeatherMap API key. It will be encrypted before storage.', 'weather-info-widget' );
                            }
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save API Key', 'weather-info-widget' ) ); ?>
        </form>
    </div>
    <?php
}

/**
 * Retrieve and decrypt the stored API key.
 *
 * @return string|WP_Error Decrypted API key on success, or WP_Error on failure.
 */
function wiw_get_decrypted_api_key() {
    $encrypted_base64 = get_option( 'wiw_encrypted_api_key', '' );
    if ( empty( $encrypted_base64 ) ) {
        return new WP_Error( 'no_api_key', __( 'No API key configured. Please enter it on the Settings page.', 'weather-info-widget' ) );
    }

    // Reconstruct passphrase and IV.
    $passphrase = SECURE_AUTH_KEY . NONCE_KEY;
    $iv         = substr( hash( 'sha256', $passphrase, true ), 0, 16 );

    // Base64-decode and decrypt.
    $encrypted = base64_decode( $encrypted_base64 );
    $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $passphrase, 0, $iv );

    if ( false === $decrypted ) {
        return new WP_Error( 'decrypt_error', __( 'Unable to decrypt API key. Please re-enter it on the Settings page.', 'weather-info-widget' ) );
    }

    return $decrypted;
}

/**
 * ------------------------------------------------------------------------
 * Section: Widget Registration & Core Functionality
 * ------------------------------------------------------------------------
 */

/**
 * Register the Weather Info Widget.
 */
function wiw_register_widget() {
    register_widget( 'WIW_Weather_Widget' );
}
add_action( 'widgets_init', 'wiw_register_widget' );

/**
 * Class WIW_Weather_Widget
 *
 * Extends WP_Widget to create a custom widget that displays current weather information.
 */
class WIW_Weather_Widget extends WP_Widget {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'wiw_weather_widget',
            esc_html__( 'Weather Info Widget', 'weather-info-widget' ),
            array( 'description' => esc_html__( 'Displays current weather for a city with styling options.', 'weather-info-widget' ) )
        );
    }

    /**
     * Front-end display of widget.
     *
     * @param array $args     Widget arguments (before_widget, before_title, etc.).
     * @param array $instance Saved values from database (title, city, unit, display_style, display_layout).
     */
    public function widget( $args, $instance ) {
        echo $args['before_widget'];

        /**
         * Action: wiw_widget_render_before
         *
         * @param array $args     Widget args (before_widget, before_title, etc.).
         * @param array $instance Current widget instance settings.
         */
        do_action( 'wiw_widget_render_before', $args, $instance );

        $title          = ! empty( $instance['title'] )          ? apply_filters( 'widget_title', $instance['title'] ) : esc_html__( 'Weather', 'weather-info-widget' );
        $city           = ! empty( $instance['city'] )           ? sanitize_text_field( $instance['city'] )           : '';
        $unit           = ! empty( $instance['unit'] )           ? sanitize_text_field( $instance['unit'] )           : 'metric';
        $display_style  = ! empty( $instance['display_style'] )  ? sanitize_text_field( $instance['display_style'] )  : 'minimal';
        $display_layout = ! empty( $instance['display_layout'] ) ? sanitize_text_field( $instance['display_layout'] ) : 'vertical';

        // Attempt to get the decrypted API key.
        $api_key_result = wiw_get_decrypted_api_key();
        if ( is_wp_error( $api_key_result ) ) {
            echo '<p>' . esc_html( $api_key_result->get_error_message() ) . '</p>';

            /**
             * Action: wiw_widget_render_after
             *
             * @param array $args     Widget args (before_widget, before_title, etc.).
             * @param array $instance Current widget instance settings.
             */
            do_action( 'wiw_widget_render_after', $args, $instance );

            echo $args['after_widget'];
            return;
        }
        $api_key = $api_key_result;

        if ( empty( $city ) ) {
            echo '<p>' . esc_html__( 'Please set a city in widget settings.', 'weather-info-widget' ) . '</p>';

            /**
             * Action: wiw_widget_render_after
             *
             * @param array $args     Widget args (before_widget, before_title, etc.).
             * @param array $instance Current widget instance settings.
             */
            do_action( 'wiw_widget_render_after', $args, $instance );

            echo $args['after_widget'];
            return;
        }

        if ( ! empty( $title ) ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }

        $weather_data = wiw_fetch_weather_data( $city, $api_key, $unit );

        if ( is_wp_error( $weather_data ) ) {
            echo '<p>' . esc_html( $weather_data->get_error_message() ) . '</p>';
        } else {
            $temp_symbol = ( 'imperial' === $unit ) ? '°F' : '°C';
            $wind_symbol = ( 'imperial' === $unit ) ? 'mph' : 'm/s';
            $icon_code   = isset( $weather_data['weather'][0]['icon'] ) ? $weather_data['weather'][0]['icon'] : '';
            $icon_url    = $icon_code ? 'https://openweathermap.org/img/wn/' . $icon_code . '@2x.png' : '';

            // Build CSS class for widget container.
            $widget_class = 'weather-info-widget-display';
            if ( 'standard' === $display_style ) {
                $widget_class .= ' weather-info-widget-standard';
            } elseif ( 'advanced' === $display_style ) {
                $widget_class .= ' weather-info-widget-advanced';
                if ( 'horizontal' === $display_layout ) {
                    $widget_class .= ' weather-info-widget-horizontal';
                }
            }

            /**
             * Filter: wiw_widget_container_class
             * Allows modification of the final container CSS classes.
             *
             * @param string $widget_class CSS class string before output.
             * @param array  $instance     Current widget instance settings.
             * @param array  $args         Widget arguments (before_widget, etc.).
             */
            $widget_class = apply_filters( 'wiw_widget_container_class', $widget_class, $instance, $args );
            
            ?>
            <div class="<?php echo esc_attr( $widget_class ); ?>">
                <?php if ( 'advanced' === $display_style ) : ?>
                    <div class="weather-card-content">
                        <div class="weather-card-header">
                            <h3 class="weather-city"><?php echo esc_html( $weather_data['name'] ); ?></h3>
                            <?php if ( $icon_url ) : ?>
                                <img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $weather_data['weather'][0]['description'] ); ?>" class="weather-icon" />
                            <?php endif; ?>
                            <p class="weather-description"><?php echo esc_html( ucfirst( $weather_data['weather'][0]['description'] ) ); ?></p>
                            <?php if ( 'horizontal' === $display_layout ) : ?>
                                <p class="weather-temp">
                                    <?php printf( esc_html__( '%s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['temp'] ) ), esc_html( $temp_symbol ) ); ?>
                                </p>

                                <p class="weather-feels-like">
                                  <small><?php printf( esc_html__( 'Feels like: %s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['feels_like'] ) ), esc_html( $temp_symbol ) ); ?></small>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="weather-card-body">
                            
                            <?php if ( 'horizontal' !== $display_layout ) : ?>
                                <p class="weather-temp">
                                    <?php printf( esc_html__( '%s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['temp'] ) ), esc_html( $temp_symbol ) ); ?>
                                </p>
                                <p class="weather-feels-like">
                                  <?php printf( esc_html__( 'Feels like: %s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['feels_like'] ) ), esc_html( $temp_symbol ) ); ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="weather-details-grid">
                                <p><span><?php esc_html_e( 'Min Temp:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['temp_min'] ) ), esc_html( $temp_symbol ) ); ?></p>
                                <p><span><?php esc_html_e( 'Max Temp:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['temp_max'] ) ), esc_html( $temp_symbol ) ); ?></p>
                                <p><span><?php esc_html_e( 'Humidity:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s%%', 'weather-info-widget' ), esc_html( $weather_data['main']['humidity'] ) ); ?></p>
                                <p><span><?php esc_html_e( 'Pressure:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s hPa', 'weather-info-widget' ), esc_html( $weather_data['main']['pressure'] ) ); ?></p>
                                <p><span><?php esc_html_e( 'Wind:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s %s', 'weather-info-widget' ), esc_html( $weather_data['wind']['speed'] ), esc_html( $wind_symbol ) ); ?></p>
                                <p><span><?php esc_html_e( 'Visibility:', 'weather-info-widget' ); ?></span> <?php printf( esc_html__( '%s km', 'weather-info-widget' ), esc_html( $weather_data['visibility'] / 1000 ) ); ?></p>
                            </div>
                        </div>
                    </div>
                <?php else : // Minimal or Standard ?>
                    <?php if ( $icon_url ) : ?>
                        <img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $weather_data['weather'][0]['description'] ); ?>" class="weather-icon-small" />
                    <?php endif; ?>
                    <p><strong><?php echo esc_html( $weather_data['name'] ); ?></strong></p>
                    <p><?php echo esc_html( ucfirst( $weather_data['weather'][0]['description'] ) ); ?></p>
                    <p>
                        <?php printf( esc_html__( 'Temperature: %s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['temp'] ) ), esc_html( $temp_symbol ) ); ?>
                    </p>
                    <?php if ( 'standard' === $display_style || 'advanced' === $display_style ) : ?>
                        <p>
                            <?php printf( esc_html__( 'Feels like: %s%s', 'weather-info-widget' ), esc_html( round( $weather_data['main']['feels_like'] ) ), esc_html( $temp_symbol ) ); ?>
                        </p>
                        <p>
                            <?php printf( esc_html__( 'Humidity: %s%%', 'weather-info-widget' ), esc_html( $weather_data['main']['humidity'] ) ); ?>
                        </p>
                        <p>
                            <?php printf( esc_html__( 'Wind Speed: %s %s', 'weather-info-widget' ), esc_html( $weather_data['wind']['speed'] ), esc_html( $wind_symbol ) ); ?>
                        </p>
                        <p>
                            <?php printf( esc_html__( 'Pressure: %s hPa', 'weather-info-widget' ), esc_html( $weather_data['main']['pressure'] ) ); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Action: wiw_widget_render_after
         *
         * @param array $args     Widget args (before_widget, before_title, etc.).
         * @param array $instance Current widget instance settings.
         */
        do_action( 'wiw_widget_render_after', $args, $instance );

        echo $args['after_widget'];
    }

    /**
     * Back-end widget form in the admin (includes styling options).
     *
     * @param array $instance Current settings.
     */
    public function form( $instance ) {
        $title           = ! empty( $instance['title'] )          ? $instance['title']          : esc_html__( 'Weather', 'weather-info-widget' );
        $city            = ! empty( $instance['city'] )           ? $instance['city']           : '';
        $unit            = ! empty( $instance['unit'] )           ? $instance['unit']           : 'metric';
        $display_style   = ! empty( $instance['display_style'] )  ? $instance['display_style']  : 'minimal';
        $display_layout  = ! empty( $instance['display_layout'] ) ? $instance['display_layout'] : 'vertical';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_attr_e( 'Title:', 'weather-info-widget' ); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                type="text"
                value="<?php echo esc_attr( $title ); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>">
                <?php esc_attr_e( 'City Name:', 'weather-info-widget' ); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'city' ) ); ?>"
                type="text"
                value="<?php echo esc_attr( $city ); ?>"
            >
            <small><?php esc_html_e( 'Enter the city (e.g., London).', 'weather-info-widget' ); ?></small>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>">
                <?php esc_attr_e( 'Temperature Unit:', 'weather-info-widget' ); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'unit' ) ); ?>"
            >
                <option value="metric"   <?php selected( $unit, 'metric' );   ?>><?php esc_html_e( 'Celsius (°C)',    'weather-info-widget' ); ?></option>
                <option value="imperial" <?php selected( $unit, 'imperial' ); ?>><?php esc_html_e( 'Fahrenheit (°F)', 'weather-info-widget' ); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'display_style' ) ); ?>">
                <?php esc_attr_e( 'Display Style:', 'weather-info-widget' ); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'display_style' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'display_style' ) ); ?>"
                onchange="
                    var layoutRow = document.getElementById('<?php echo esc_attr( $this->get_field_id( 'display_layout' ) ); ?>-row');
                    if ( this.value === 'advanced' ) {
                        layoutRow.style.display = 'block';
                    } else {
                        layoutRow.style.display = 'none';
                    }
                "
            >
                <option value="minimal"  <?php selected( $display_style, 'minimal' );  ?>><?php esc_html_e( 'Minimal (Theme Styling)',      'weather-info-widget' ); ?></option>
                <option value="standard" <?php selected( $display_style, 'standard' ); ?>><?php esc_html_e( 'Standard (Basic Styling)',    'weather-info-widget' ); ?></option>
                <option value="advanced" <?php selected( $display_style, 'advanced' ); ?>><?php esc_html_e( 'Advanced (Weather Card)',     'weather-info-widget' ); ?></option>
            </select>
        </p>
        <p id="<?php echo esc_attr( $this->get_field_id( 'display_layout' ) ); ?>-row" style="display: <?php echo ( 'advanced' === $display_style ) ? 'block' : 'none'; ?>;">
            <label for="<?php echo esc_attr( $this->get_field_id( 'display_layout' ) ); ?>">
                <?php esc_attr_e( 'Card Layout:', 'weather-info-widget' ); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'display_layout' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'display_layout' ) ); ?>"
            >
                <option value="vertical"   <?php selected( $display_layout, 'vertical' );   ?>><?php esc_html_e( 'Vertical',   'weather-info-widget' ); ?></option>
                <option value="horizontal" <?php selected( $display_layout, 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'weather-info-widget' ); ?></option>
            </select>
        </p>
        <?php
    }

    /**
     * Sanitize widget form values as they are saved.
     * Invalidate any old cache if city or unit changed.
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     *
     * @return array Updated safe values to be saved.
     */
    public function update( $new_instance, $old_instance ) {
        $instance                  = array();
        $instance['title']         = ! empty( $new_instance['title'] )         ? sanitize_text_field( $new_instance['title'] )         : '';
        $instance['city']          = ! empty( $new_instance['city'] )          ? sanitize_text_field( $new_instance['city'] )          : '';
        $instance['unit']          = ! empty( $new_instance['unit'] )          ? sanitize_text_field( $new_instance['unit'] )          : 'metric';
        $instance['display_style'] = ! empty( $new_instance['display_style'] ) ? sanitize_text_field( $new_instance['display_style'] ) : 'minimal';
        $instance['display_layout']= ! empty( $new_instance['display_layout'] )? sanitize_text_field( $new_instance['display_layout'] ): 'vertical';

        // Compare with old values to determine if cache should be deleted.
        $old_city = isset( $old_instance['city'] ) ? sanitize_text_field( $old_instance['city'] ) : '';
        $old_unit = isset( $old_instance['unit'] ) ? sanitize_text_field( $old_instance['unit'] ) : 'metric';

        // If city changed or unit changed, delete the old transient.
        if ( $old_city && ( $old_city !== $instance['city'] || $old_unit !== $instance['unit'] ) ) {
            $old_key = 'wiw_weather_data_' . md5( strtolower( $old_city ) . '_' . $old_unit );
            delete_transient( $old_key );
        }

        // Schedule or unschedule cron job based on whether a city is provided.
        if ( ! empty( $instance['city'] ) ) {
            wiw_schedule_update( $instance['city'] );
        } else {
            wiw_unschedule_update();
        }

        return $instance;
    }
}

/**
 * ------------------------------------------------------------------------
 * Section: Core Functions (Data Fetching, Caching, and WP Cron)
 * ------------------------------------------------------------------------
 */

/**
 * Fetches weather data from the OpenWeather API and caches it for one hour.
 *
 * @param string $city    City name (e.g., "London").
 * @param string $api_key Decrypted API key.
 * @param string $unit    Unit ("metric" or "imperial").
 * @return array|WP_Error Decoded weather data on success; WP_Error on failure.
 */
function wiw_fetch_weather_data( $city, $api_key, $unit = 'metric' ) {
    $transient_key = 'wiw_weather_data_' . md5( strtolower( $city ) . '_' . $unit );
    $cached_data   = get_transient( $transient_key );
    if ( false !== $cached_data ) {
        return $cached_data;
    }

    $api_url = 'https://api.openweathermap.org/data/2.5/weather'
        . '?q=' . rawurlencode( $city )
        . '&appid=' . rawurlencode( $api_key )
        . '&units=' . rawurlencode( $unit );

    // DEFAULT HTTP ARGS
    $http_args = array(
        'timeout' => 10,
    );

    /**
     * Filter: wiw_fetch_weather_data_args
     * Allows URL or HTTP args adjustment before the API call.
     *
     * @param array {
     *     @type string $url      Full API URL.
     *     @type array  $args     wp_remote_get() arguments.
     * }
     * @param string $city Current city.
     * @param string $unit Current unit (metric|imperial).
     */
    $filtered = apply_filters( 'wiw_fetch_weather_data_args', array(
        'url'  => $api_url,
        'args' => $http_args,
    ), $city, $unit );

    // Use possibly modified URL/args
    $api_url  = $filtered['url'];
    $http_args = $filtered['args'];

    $response = wp_remote_get( $api_url, $http_args );
    if ( is_wp_error( $response ) ) {
        return new WP_Error(
            'wiw_network_error',
            __( 'Network error: Could not connect to OpenWeather API.', 'weather-info-widget' ) . ' ' . $response->get_error_message()
        );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $body          = wp_remote_retrieve_body( $response );
    $data          = json_decode( $body, true );

    if ( 200 !== intval( $response_code ) ) {
        $error_message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : __( 'Unknown API error.', 'weather-info-widget' );
        return new WP_Error(
            'wiw_api_error',
            sprintf( esc_html__( 'OpenWeather API error (%1$d): %2$s', 'weather-info-widget' ), intval( $response_code ), $error_message )
        );
    }

    if ( empty( $data ) || ! is_array( $data ) ) {
        return new WP_Error( 'wiw_parse_error', __( 'Failed to parse weather data from API.', 'weather-info-widget' ) );
    }

    /**
     * Filter: wiw_weather_data
     * Allows modification of the decoded weather data before caching/return.
     *
     * @param array  $data Raw decoded API response.
     * @param string $city Current city.
     * @param string $unit Current unit.
     */
    $data = apply_filters( 'wiw_weather_data', $data, $city, $unit );

    $default_ttl = HOUR_IN_SECONDS;

    /**
     * Filter: wiw_transient_expiration
     * Override default cache expiration (seconds).
     *
     * @param int    $ttl   Default TTL in seconds.
     * @param string $city  Current city.
     * @param string $unit  Current unit.
     */
    $ttl = apply_filters( 'wiw_transient_expiration', $default_ttl, $city, $unit );
    set_transient( $transient_key, $data, $ttl );

    return $data;
}

/**
 * Schedule the hourly WP Cron job to refresh weather data.
 *
 * Stores the latest widget city in an option for the cron callback.
 *
 * @param string $city City name to schedule for.
 */
function wiw_schedule_update( $city ) {
    if ( ! wp_next_scheduled( 'wiw_hourly_update' ) ) {
        wp_schedule_event( time(), 'hourly', 'wiw_hourly_update' );
    }
    update_option( 'wiw_cron_city', sanitize_text_field( $city ) );
}

/**
 * Unschedule the hourly WP Cron job and clear stored city.
 */
function wiw_unschedule_update() {
    $timestamp = wp_next_scheduled( 'wiw_hourly_update' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wiw_hourly_update' );
    }
    delete_option( 'wiw_cron_city' );
}

/**
 * Callback for the hourly WP Cron event.
 *
 * Retrieves the stored city and triggers a fresh API fetch (which caches the data).
 */
function wiw_do_hourly_update() {
    $city = get_option( 'wiw_cron_city', '' );
    if ( empty( $city ) ) {
        return;
    }

    $api_key_result = wiw_get_decrypted_api_key();
    if ( is_wp_error( $api_key_result ) ) {
        return;
    }
    $api_key = $api_key_result;

    wiw_fetch_weather_data( $city, $api_key );
}
add_action( 'wiw_hourly_update', 'wiw_do_hourly_update' );

/**
 * On plugin activation: if a city is saved, schedule the cron job.
 */
function wiw_activate_plugin() {
    $city = get_option( 'wiw_cron_city', '' );
    if ( ! empty( $city ) ) {
        wiw_schedule_update( $city );
    }
}
register_activation_hook( __FILE__, 'wiw_activate_plugin' );

/**
 * On plugin deactivation: unschedule the cron job and clean up.
 */
function wiw_deactivate_plugin() {
    wiw_unschedule_update();
}
register_deactivation_hook( __FILE__, 'wiw_deactivate_plugin' );

/**
 * ------------------------------------------------------------------------
 * Section: Styles Enqueue (Optional)
 * ------------------------------------------------------------------------
 */

/**
 * Enqueue plugin styles (if you have a style.css file in the same folder).
 */
function wiw_enqueue_styles() {
    wp_enqueue_style(
        'wiw-weather-widget-style',
        plugins_url( 'style.css', __FILE__ ),
        array(),
        '1.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'wiw_enqueue_styles' );
