<?php
/*
Plugin Name: Login OpenID Client
Description:  Allow users to register and login to your website with OpenID provider.
Version: 1.0.1
Author: Steven Buriek
License: GPLv3
*/

define('LOGIN_DISCOVERY_URL', '');

class Login_OpenID {
	// plugin version
	const VERSION = '1.0.1';

	// openid client
	private $client;

	// settings admin page
	private $settings_page;

	// login form adjustments
	private $login_form;

	/**
	 * Setup the plugin
	 */
	function __construct(  ){
		
	}

	/**
	 * WP Hook 'init'
	 */
	function init(){

		$redirect_uri = admin_url( 'admin-ajax.php?action=login-openid-authorize' );
		$state_time_limit = 180;

		$login_registration_data = get_option('ls-registration-data');
		$api_data = get_option('login-api-data' );
		
		$client_id = '';
		$client_secret = '';
		
		if($login_registration_data){	
			$client_id = $login_registration_data->client_id;
			$client_secret = $login_registration_data->client_secret;
		}
		
		$this->client = new Login_OpenID_Client(
			$client_id,
			$client_secret,
			'openid email name phone',
			$api_data['authorization_endpoint'],
			$api_data['userinfo_endpoint'],
			$api_data['token_endpoint'],
	 		$redirect_uri,
	 		$state_time_limit
		);

		add_action('login_enqueue_scripts', array($this, 'login_styles'));

		$this->client_wrapper = Login_OpenID_Client_Wrapper::register( $this->client );
		$this->login_form = Login_OpenID_Login_Form::register( $this->client_wrapper );

		// add a shortcode to get the auth url
		add_shortcode( 'Login_OpenID_auth_url', array( $this->client_wrapper, 'get_authentication_url' ) );

		if ( is_admin() ){
			$this->settings_page = Login_OpenID_Settings_Page::register();
		}
	}

	/**
	 * Simple autoloader
	 *
	 * @param $class
	 */
	static public function autoload( $class ) {
		$prefix = 'Login_OpenID_';

		if ( stripos($class, $prefix) !== 0 ) {
			return;
		}

		$filename = $class . '.php';

		// internal files are all lowercase and use dashes in filenames
		if ( false === strpos( $filename, '\\' ) ) {
			$filename = strtolower( str_replace( '_', '-', $filename ) );
		}
		else {
			$filename  = str_replace('\\', DIRECTORY_SEPARATOR, $filename);
		}

		$filepath = dirname( __FILE__ ) . '/includes/' . $filename;

		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WP
	 */
	static public function bootstrap(){
		spl_autoload_register( array( 'Login_OpenID', 'autoload' ) );

		$plugin = new self();

		// Register activation hook.
		register_activation_hook( __FILE__, array( $plugin, 'activation' ) );
		
		add_action( 'init', array( $plugin, 'init' ) );
		add_action( 'wp_footer', array( $plugin, 'footer_stuff' ) );
	}

	static public function footer_stuff(){
	}
	
	/**
	 * Load jQuery and add custom stylesheets for Login login button
	 */
	static public function login_login_styles() {
		wp_enqueue_script('jquery');
		 ?>
		<style type="text/css">
			form#loginform p.galogin {
				background: none repeat scroll 0 0 #2EA2CC;
				border-color: #0074A2;
				box-shadow: 0 1px 0 rgba(120, 200, 230, 0.5) inset, 0 1px 0 rgba(0, 0, 0, 0.15);
				color: #FFFFFF;
				text-decoration: none;
				text-align: center;
				vertical-align: middle;
				border-radius: 3px;
				padding: 4px;
				height: 27px;
				font-size: 14px;
			}
			
			form#loginform p.galogin a {
				color: #FFFFFF;
				line-height: 27px;
				font-weight: bold;
			}

			form#loginform p.galogin img {
				vertical-align: middle;
			}

			form#loginform p.galogin a:hover {
				color: #CCCCCC;
			}
			
			h3.galogin-or {
				text-align: center;
				margin-top: 16px;
				margin-bottom: 16px;
			}

			
		 </style>
	<?php }

	/**
	 * Run this when plugin is initiated first time
	*/
	static public function activation(){
		$api_data = get_option('login-api-data' );
		if(!$api_data){
			$response = wp_remote_get(LOGIN_DISCOVERY_URL);
			if ( is_wp_error( $response ) ){
				die('Not able to activate plugin as we\'re not able to connect to your website.');
			}
			// extract token response from token
			$api_data = json_decode( $response['body'], true );
			update_option('login-api-data', $api_data);

			// Register website as a client 
			$redirect_uris = array( admin_url( 'admin-ajax.php?action=login-openid-authorize' ) );
			
			$client_name = sanitize_title(get_bloginfo('name')) . '-wp';
			
			$request_body = array(
				'redirect_uris' => $redirect_uris,
				'response_types' => array('code'),
				'grant_types' => array('authorization_code'),
				'require_auth_time'  => true,
				'client_name'    => $client_name
			);
			$request = array(
				'body' => json_encode($request_body),
				'headers' => array(
					'Content-type' => 'application/json'
				)
			);
			
			$response = wp_remote_post($api_data['registration_endpoint'], $request);

			if ( is_wp_error( $response ) ){
				die('Not able to connect to website.');
			}
			// extract token response from token
			$reg_data = json_decode($response['body']);
			if(isset($reg_data->error)){
				die($reg_data->error_description);
			}
			else{
				update_option('ls-registration-data', $reg_data);
			}
		}
	}
}

Login_OpenID::bootstrap();
