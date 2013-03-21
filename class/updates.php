<?php

class WPUF_Updates {

    const base_url = 'http://localhost/wedevs/';
    const product_id = 'wp-user-frontendpro-single';
    const option = 'wpuf_license';
    const slug = 'wp-user-frontend-pro';

    function __construct() {

        add_action( 'wpuf_admin_menu', array($this, 'admin_menu'), 99 );
        add_action( 'admin_notices', array($this, 'license_enter_notice') );
        add_action( 'admin_notices', array($this, 'license_check_notice') );

        add_filter( 'pre_set_site_transient_update_plugins', array($this, 'check_update') );
        add_filter( 'plugins_api', array(&$this, 'check_info'), 10, 3 );
    }

    function admin_menu() {
        add_submenu_page( 'wpuf-admin-opt', __( 'Updates', 'wpuf' ), __( 'Updates', 'wpuf' ), 'activate_plugins', 'wpuf_updates', array($this, 'plugin_update') );
    }

    function get_license_key() {
        return get_option( self::option, array() );
    }

    function license_enter_notice() {
        if ( $key = $this->get_license_key() ) {
            return;
        }
        ?>
        <div class="error">
            <p><?php printf( __( 'Please <a href="%s">enter</a> your <strong>WP User Frontend</strong> plugin license key to get regular update and support.' ), admin_url( 'admin.php?page=wpuf_updates' ) ); ?></p>
        </div>
        <?php
    }

    function license_check_notice() {
        if ( !$key = $this->get_license_key() ) {
            return;
        }

        $trans = get_transient( self::option );
        if ( false === $trans ) {
            $trans = $this->activation();

            $duration = 60 * 30; // half hour
            set_transient( self::option, $trans, $duration );
        }

        if ( $trans->activated ) {
            return;
        }
        ?>
        <div class="error">
            <p><strong><?php _e( 'WP User Frontend Error:', 'wpuf' ); ?></strong> <?php echo $trans->error; ?></p>
        </div>
        <?php
    }

    // Fire away!
    function execute_request( $args ) {
        $target_url = $this->create_url( $args );
        $data = wp_remote_get( $target_url );

        return json_decode( $data['body'] );
    }

    // Create an url based on
    function create_url( $args ) {

        $base_url = add_query_arg( 'wc-api', 'software-api', self::base_url );

        return $base_url . '&' . http_build_query( $args );
    }

    function activation() {
        if ( !$option = $this->get_license_key() ) {
            return;
        }

        $args = array(
            'request' => 'activation',
            'email' => $option['email'],
            'licence_key' => $option['key'],
            'product_id' => self::product_id,
            'instance' => home_url()
        );

        return $this->execute_request( $args );
    }

    function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote_info = $this->get_info();

        if ( !$remote_info ) {
            return $transient;
        }
        
        list( $plugin_name, $plugin_version) = $this->get_current_plugin_info();

        if ( version_compare( $plugin_version, $remote_info->latest, '<' ) ) {

            $obj = new stdClass();
            $obj->slug = self::slug;
            $obj->new_version = $remote_info->latest;

            if ( isset( $remote_info->latest_url ) ) {
                $obj->package = $remote_info->latest_url;
            }

            $basefile = plugin_basename( dirname( dirname( __FILE__ ) ) . '/wpuf.php' );
            $transient->response[$basefile] = $obj;

            var_dump( $transient );
        }

        return $transient;
    }

    function check_info( $false, $action, $args ) {
        if ( self::slug == $args->slug ) {
            echo '<br>check info start<br>';
            var_dump( $false, $action, $args );
            echo '<br>check info end<br>';
        }

        return false;
    }

    function get_current_plugin_info() {
        require_once ABSPATH . '/wp-admin/includes/plugin.php';

        $plugin_data = get_plugin_data( dirname( dirname( __FILE__ ) ) . '/wpuf.php' );
        $plugin_name = $plugin_data['Name'];
        $plugin_version = $plugin_data['Version'];

        return array($plugin_name, $plugin_version);
    }

    function get_info() {
        global $wp_version, $wpdb;

        list( $plugin_name, $plugin_version) = $this->get_current_plugin_info();

        if ( is_multisite() ) {
            $user_count = get_user_count();
            $num_blogs = get_blog_count();
            $wp_install = network_site_url();
            $multisite_enabled = 1;
        } else {
            $user_count = count_users();
            $multisite_enabled = 0;
            $num_blogs = 1;
            $wp_install = home_url( '/' );
        }

        $locale = apply_filters( 'core_version_check_locale', get_locale() );

        if ( method_exists( $wpdb, 'db_version' ) )
            $mysql_version = preg_replace( '/[^0-9.].*/', '', $wpdb->db_version() );
        else
            $mysql_version = 'N/A';

        $license = $this->get_license_key();

        $params = array(
            'timeout' => ( ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 30 : 3 ),
            'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url( '/' ),
            'body' => array(
                'name' => $plugin_name,
                'slug' => self::slug,
                'type' => 'plugin',
                'version' => $plugin_version,
                'wp_version' => $wp_version,
                'php_version' => phpversion(),
                'action' => 'theme_check',
                'locale' => $locale,
                'mysql' => $mysql_version,
                'blogs' => $num_blogs,
                'users' => $user_count['total_users'],
                'multisite_enabled' => $multisite_enabled,
                'site_url' => $wp_install,
                'license' => isset( $license['key'] ) ? $license['key'] : '',
                'license_email' => isset( $license['email'] ) ? $license['email'] : '',
                'product_id' => self::product_id
            )
        );

        $response = wp_remote_post( self::base_url . '?action=wedevs_update_check', $params );
        $update = wp_remote_retrieve_body( $response );

        if ( is_wp_error( $response ) || $response['response']['code'] != 200 ) {
            return false;
        }

        return json_decode( $update );
    }

    function plugin_update() {
        $errors = array();
        if ( isset( $_POST['submit'] ) ) {
            if ( empty( $_POST['email'] ) ) {
                $errors[] = __( 'Empty email address', 'wpuf' );
            }

            if ( empty( $_POST['license_key'] ) ) {
                $errors[] = __( 'Empty license key', 'wpuf' );
            }

            if ( !$errors ) {
                update_option( self::option, array('email' => $_POST['email'], 'key' => $_POST['license_key']) );
                delete_transient( self::option );

                echo '<div class="updated"><p>' . __( 'Settings Saved', 'wpuf' ) . '</p></div>';
            }
        }

        $license = $this->get_license_key();
        $email = $license ? $license['email'] : '';
        $key = $license ? $license['key'] : '';
        ?>
        <div class="wrap">
            <?php screen_icon( 'plugins' ); ?>
            <h2><?php _e( 'Update Manager', 'wpuf' ); ?></h2>

            <p class="description">
                Enter the E-mail address that was used for purchasing the plugin and the license key.
                We recommend you to enter those details to get regular <strong>plugin update and support</strong>.
            </p>

            <?php
            if ( $errors ) {
                foreach ($errors as $error) {
                    ?>
                    <div class="error"><p><?php echo $error; ?></p></div>
                    <?php
                }
            }
            ?>

            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th><?php _e( 'E-mail Address', 'wpuf' ); ?></th>
                        <td>
                            <input type="email" name="email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" required>
                            <span class="description"><?php _e( 'Enter your purchase Email address', 'wpuf' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e( 'License Key', 'wpuf' ); ?></th>
                        <td>
                            <input type="text" name="license_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>">
                            <span class="description"><?php _e( 'Enter your license key', 'wpuf' ); ?></span>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Changes' ); ?>
            </form>
        </div>
        <?php
    }

}