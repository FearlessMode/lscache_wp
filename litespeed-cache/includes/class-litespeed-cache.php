<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/includes
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
class LiteSpeed_Cache extends LiteSpeed{
	private $config;

	const PLUGIN_NAME = 'litespeed-cache';
	const PLUGIN_VERSION = '1.0.16';

	const LSCOOKIE_VARY_NAME = 'LSCACHE_VARY_COOKIE' ;
	const LSCOOKIE_DEFAULT_VARY = '_lscache_vary' ;
	const LSCOOKIE_VARY_LOGGED_IN = 1;
	const LSCOOKIE_VARY_COMMENTER = 2;

	const PAGE_EDIT_HTACCESS = 'lscache-edit-htaccess';

	const NONCE_NAME = 'LSCWP_NONCE';
	const ACTION_KEY = 'LSCWP_CTRL';
	const ACTION_SAVE_HTACCESS = 'save-htaccess';
	const ACTION_SAVE_SETTINGS = 'save-settings';
	const ACTION_SAVE_SETTINGS_NETWORK = 'save-settings-network';
	const ACTION_DO_CRAWL = 'do-crawl';
	const ACTION_DISMISS = 'DISMISS';
	const ACTION_PURGE = 'PURGE';
	const ACTION_PURGE_ERRORS = 'PURGE_ERRORS';
	const ACTION_PURGE_PAGES = 'PURGE_PAGES';
	const ACTION_PURGE_BY = 'PURGE_BY';
	const ACTION_PURGE_FRONT = 'PURGE_FRONT';
	const ACTION_PURGE_ALL = 'PURGE_ALL';
	const ACTION_PURGE_EMPTYCACHE = 'PURGE_EMPTYCACHE';
	const ACTION_PURGE_SINGLE = 'PURGESINGLE';
	const ACTION_SHOW_HEADERS = 'SHOWHEADERS';
	const ACTION_NOCACHE = 'NOCACHE';
	const ACTION_CRAWLER_GENERATE_FILE = '';

	const ADMINNONCE_PURGEALL = 'litespeed-purgeall';
	const ADMINNONCE_PURGENETWORKALL = 'litespeed-purgeall-network';
	const ADMINNONCE_PURGEBY = 'litespeed-purgeby';

	const CACHECTRL_NOCACHE = 0;
	const CACHECTRL_CACHE = 1;
	const CACHECTRL_PURGE = 2;
	const CACHECTRL_PURGESINGLE = 3;

	const CACHECTRL_SHOWHEADERS = 128; // (1<<7)
	const CACHECTRL_STALE = 64; // (1<<6)

	const WHM_TRANSIENT = 'lscwp_whm_install';
	const WHM_TRANSIENT_VAL = 'whm_install';
	const NETWORK_TRANSIENT_COUNT = 'lscwp_network_count';

	protected $plugin_dir ;
	protected $current_vary;
	protected $cachectrl = self::CACHECTRL_NOCACHE;
	protected $pub_purge_tags = array();
	protected $error_status = false;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	protected function __construct(){
		$this->config = LiteSpeed_Cache_Config::get_instance();

		// Check if debug is on
		if ($this->config->get_option(LiteSpeed_Cache_Config::OPID_ENABLED)) {
			$should_debug = intval($this->config->get_option(LiteSpeed_Cache_Config::OPID_DEBUG));
			switch ($should_debug) {
				// NOTSET is used as check admin IP here.
				case 2:
					if (!LiteSpeed_Cache_Router::is_admin_ip()) {
						break;
					}
					// fall through
				case 1:
					LiteSpeed_Cache_Log::set_enabled();
					break;
				default:
					break;
			}
		}

		// Load third party detection.
		include_once LSWCP_DIR . 'thirdparty/litespeed-cache-thirdparty-registry.php';
		// Register plugin activate/deactivate/uninstall hooks
		$plugin_file = LSWCP_DIR . 'litespeed-cache.php';
		register_activation_hook($plugin_file, array( $this, 'register_activation' )) ;
		register_deactivation_hook($plugin_file, array( $this, 'register_deactivation' )) ;
		register_uninstall_hook($plugin_file, 'LiteSpeed_Cache::uninstall_litespeed_cache');

		add_action('after_setup_theme', array( $this, 'init' )) ;
	}

	/**
	 * The plugin initializer.
	 *
	 * This function checks if the cache is enabled and ready to use, then
	 * determines what actions need to be set up based on the type of user
	 * and page accessed. Output is buffered if the cache is enabled.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function init(){
		if(!empty($_REQUEST[LiteSpeed_Cache::ACTION_KEY])) {
			$this->cachectrl = self::CACHECTRL_NOCACHE;
		}

		if(is_admin()) {
			LiteSpeed_Cache_Admin::get_instance();
		}

		if (!$this->config->is_plugin_enabled()
			|| !defined('LSCACHE_ADV_CACHE')
			|| !LSCACHE_ADV_CACHE
		) {
			return;
		}

		define('LITESPEED_CACHE_ENABLED', true);
		ob_start();
		//TODO: Uncomment this when esi is implemented.
//		add_action('init', array($this, 'check_admin_bar'), 0);
//		$this->add_actions_esi();
		add_action('shutdown', array($this, 'send_headers'), 0);

		$bad_cookies = $this->setup_cookies();

		// if ( $this->check_esi_page()) {
		// 	return;
		// }

		// do litespeed actions
		$this->proceed_action();

		if (!$bad_cookies && !$this->check_user_logged_in() && !$this->check_cookies()) {
			$this->load_logged_out_actions();
		}

		// Load public hooks
		$this->load_public_actions();

		if (LiteSpeed_Cache_Router::is_ajax()) {
			add_action('init', array($this, 'detect'), 4);
		}
		elseif (is_admin() || is_network_admin()) {
			add_action('admin_init', array($this, 'detect'), 0);
		}
		else {
			add_action('wp', array($this, 'detect'), 4);
		}

	}


	public function proceed_action(){
		$msg = false;
		// handle common actions
		switch (LiteSpeed_Cache_Router::get_action()) {

			// Save htaccess
			case LiteSpeed_Cache::ACTION_SAVE_HTACCESS:
				LiteSpeed_Cache_Admin_Rules::get_instance()->htaccess_editor_save();
				break;

			// Save network settings
			case LiteSpeed_Cache::ACTION_SAVE_SETTINGS_NETWORK:
				LiteSpeed_Cache_Admin_Settings::get_instance()->validate_network_settings();// todo: use wp network setting saving
				LiteSpeed_Cache_Admin_Report::get_instance()->update_environment_report();
				break;

			case LiteSpeed_Cache::ACTION_PURGE_FRONT:
				LiteSpeed_Cache::get_instance()->purge_front();
				$msg = __('Notified LiteSpeed Web Server to purge the front page.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE_PAGES:
				LiteSpeed_Cache::get_instance()->purge_pages();
				$msg = __('Notified LiteSpeed Web Server to purge pages.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE_ERRORS:
				LiteSpeed_Cache::get_instance()->purge_errors();
				$msg = __('Notified LiteSpeed Web Server to purge error pages.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE_ALL:
				LiteSpeed_Cache::get_instance()->purge_all();
				$msg = __('Notified LiteSpeed Web Server to purge the public cache.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE_EMPTYCACHE:
				LiteSpeed_Cache::get_instance()->purge_all();
				$msg = __('Notified LiteSpeed Web Server to purge everything.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE_BY:
				LiteSpeed_Cache::get_instance()->purge_list();
				$msg = __('Notified LiteSpeed Web Server to purge the list.', 'litespeed-cache');
				break;

			case LiteSpeed_Cache::ACTION_PURGE:
				$this->cachectrl = LiteSpeed_Cache::CACHECTRL_PURGE;
				break;

			case LiteSpeed_Cache::ACTION_PURGE_SINGLE:
				$this->cachectrl = LiteSpeed_Cache::CACHECTRL_PURGESINGLE;
				break;

			case LiteSpeed_Cache::ACTION_DISMISS:
				delete_transient(LiteSpeed_Cache::WHM_TRANSIENT);
				$this->admin_ctrl_redirect();
				return;

			// Handle the ajax request to proceed crawler manually by admin
			case LiteSpeed_Cache::ACTION_DO_CRAWL:
				add_action('wp_ajax_crawl_data', array(LiteSpeed_Cache_Admin_Crawler::get_instance(), 'crawl_data'));
				add_action('wp_ajax_nopriv_crawl_data', array(LiteSpeed_Cache_Admin_Crawler::get_instance(), 'crawl_data'));
				break;

			default:
				break;
		}

		if($msg) {
			LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, $msg);
			LiteSpeed_Cache::get_instance()->admin_ctrl_redirect();
			return;
		}

	}

	/**
	 * Callback used to call the detect third party action.
	 *
	 * The detect action is used by third party plugin integration classes
	 * to determine if they should add the rest of their hooks.
	 *
	 * @since 1.0.5
	 * @access public
	 */
	public function detect(){
		do_action('litespeed_cache_detect_thirdparty');
	}

	/**
	 * Register all the hooks for non-logged in users.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_logged_out_actions(){
		// user is not logged in
		add_action('wp', array( $this, 'check_cacheable' ), 5) ;
		add_action('login_init', array( $this, 'check_login_cacheable' ), 5) ;
		add_filter('status_header', array($this, 'check_error_codes'), 10, 2);

		$cache_res = $this->config->get_option(LiteSpeed_Cache_Config::OPID_CACHE_RES);
		if ($cache_res) {
			$uri = esc_url($_SERVER["REQUEST_URI"]);
			$pattern = '!' . LiteSpeed_Cache_Admin_Rules::RW_PATTERN_RES . '!';
			if (preg_match($pattern, $uri)) {
				add_action('wp_loaded', array( $this, 'check_cacheable' ), 5) ;
			}
		}
	}

	/**
	 * Register all of the hooks related to the all users
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_public_actions(){
		//register purge actions
		$purge_post_events = array(
			'edit_post',
			'save_post',
			'deleted_post',
			'trashed_post',
			'delete_attachment',
		) ;
		foreach ( $purge_post_events as $event ) {
			// this will purge all related tags
			add_action($event, array( $this, 'purge_post' ), 10, 2) ;
		}

		add_action('wp_update_comment_count', array($this, 'purge_feeds'));

		// purge_single_post will only purge that post by tag
		add_action('lscwp_purge_single_post', array($this, 'purge_single_post'));

		// register recent posts widget tag before theme renders it to make it work
		add_filter('widget_posts_args', array($this, 'register_tag_widget_recent_posts'));

		// TODO: private purge?
		// TODO: purge by category, tag?
	}

	/**
	 * A shortcut to get the LiteSpeed_Cache_Config config value
	 *
	 * @since 1.0.0
	 * @access public
	 * @param string $opt_id An option ID if getting an option.
	 * @return the option value
	 */
	public static function config($opt_id){
		return LiteSpeed_Cache_Config::get_instance()->get_option($opt_id);
	}

	/**
	 * The activation hook callback.
	 *
	 * Attempts to set up the advanced cache file. If it fails for any reason,
	 * the plugin will not activate.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function register_activation(){
		$count = 0;
		if (!defined('LSCWP_LOG_TAG')) {
			define('LSCWP_LOG_TAG', 'LSCACHE_WP_activate_' . get_current_blog_id());
		}
		$this->try_copy_advanced_cache();
		$this->config->wp_cache_var_setter(true);

		if (is_multisite()) {
			$count = $this->get_network_count();
			if ($count !== false) {
				$count = intval($count) + 1;
				set_site_transient(self::NETWORK_TRANSIENT_COUNT, $count, DAY_IN_SECONDS);
			}
		}
		do_action('litespeed_cache_detect_thirdparty');
		$this->config->plugin_activation($count);
		LiteSpeed_Cache_Admin_Report::get_instance()->generate_environment_report();

		if (defined('LSCWP_PLUGIN_NAME')) {
			set_transient(self::WHM_TRANSIENT, self::WHM_TRANSIENT_VAL);
		}

		// Register crawler cron task
		// $this->scheduleCron();
	}

	/**
	 * Uninstall plugin
	 * @since 1.0.16
	 */
	public static function uninstall_litespeed_cache(){
		LiteSpeed_Cache_Admin_Rules::get_instance()->clear_rules();
		delete_option(LiteSpeed_Cache_Config::OPTION_NAME);
		if (is_multisite()) {
			delete_site_option(LiteSpeed_Cache_Config::OPTION_NAME);
		}
	}

	/**
	 * Get the blog ids for the network. Accepts function arguments.
	 *
	 * Will use wp_get_sites for WP versions less than 4.6
	 *
	 * @since 1.0.12
	 * @access private
	 * @param array $args Arguments to pass into get_sites/wp_get_sites.
	 * @return array The array of blog ids.
	 */
	private static function get_network_ids($args = array())
	{
		global $wp_version;
		if (version_compare($wp_version, '4.6', '<')) {
			$blogs = wp_get_sites($args);
			if (!empty($blogs)) {
				foreach ($blogs as $key => $blog) {
					$blogs[$key] = $blog['blog_id'];
				}
			}
		}
		else {
			$args['fields'] = 'ids';
			$blogs = get_sites($args);
		}
		return $blogs;
	}

	/**
	 * Gets the count of active litespeed cache plugins on multisite.
	 *
	 * @since 1.0.12
	 * @access private
	 * @return mixed The count on success, false on failure.
	 */
	private function get_network_count()
	{
		$count = get_site_transient(self::NETWORK_TRANSIENT_COUNT);
		if ($count !== false) {
			return intval($count);
		}
		// need to update
		$default = array();
		$count = 0;

		$sites = $this->get_network_ids(array('deleted' => 0));
		if (empty($sites)) {
			return false;
		}

		foreach ($sites as $site) {
			$plugins = get_blog_option($site->blog_id, 'active_plugins', $default);
			if (in_array(LSWCP_BASENAME, $plugins, true)) {
				$count++;
			}
		}
		if (is_plugin_active_for_network(LSWCP_BASENAME)) {
			$count++;
		}
		return $count;
	}

	/**
	 * Is this deactivate call the last active installation on the multisite
	 * network?
	 *
	 * @since 1.0.12
	 * @access private
	 * @return bool True if yes, false otherwise.
	 */
	private function is_deactivate_last(){
		$count = $this->get_network_count();
		if ($count === false) {
			return false;
		}
		if ($count !== 1) {
			// Not deactivating the last one.
			$count--;
			set_site_transient(self::NETWORK_TRANSIENT_COUNT, $count, DAY_IN_SECONDS);
			return false;
		}

		delete_site_transient(self::NETWORK_TRANSIENT_COUNT);
		return true;
	}

	/**
	 * The deactivation hook callback.
	 *
	 * Initializes all clean up functionalities.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function register_deactivation(){
		if (!defined('LSCWP_LOG_TAG')) {
			define('LSCWP_LOG_TAG', 'LSCACHE_WP_deactivate_' . get_current_blog_id());
		}
		$this->purge_all();

		if (is_multisite()) {
			if (is_network_admin()) {
				$options = get_site_option(LiteSpeed_Cache_Config::OPTION_NAME);
				if (isset($options) && is_array($options)) {
					$opt_str = serialize($options);
					update_site_option(LiteSpeed_Cache_Config::OPTION_NAME, $opt_str);
				}
			}
			if (!$this->is_deactivate_last()) {
				if (is_network_admin() && isset($opt_str) && $options[LiteSpeed_Cache_Config::NETWORK_OPID_ENABLED]) {
					$reset = LiteSpeed_Cache_Config::get_rule_reset_options();
					$errors = array();
					LiteSpeed_Cache_Admin_Rules::get_instance()->validate_common_rewrites($reset, $errors);
				}
				return;
			}
		}

		$adv_cache_path = WP_CONTENT_DIR . '/advanced-cache.php';
		if (file_exists($adv_cache_path) && is_writable($adv_cache_path)) {
			unlink($adv_cache_path) ;
		}
		else {
			error_log('Failed to remove advanced-cache.php, file does not exist or is not writable!') ;
		}

		if (!LiteSpeed_Cache_Config::wp_cache_var_setter(false)) {
			error_log('In wp-config.php: WP_CACHE could not be set to false during deactivation!') ;
		}
		LiteSpeed_Cache_Admin_Rules::get_instance()->clear_rules();
		// delete in case it's not deleted prior to deactivation.
		delete_transient(self::WHM_TRANSIENT);
	}


	/**
	 * Try to copy our advanced-cache.php file to the wordpress directory.
	 *
	 * @since 1.0.11
	 * @access public
	 * @return boolean True on success, false on failure.
	 */
	public function try_copy_advanced_cache(){
		$adv_cache_path = WP_CONTENT_DIR . '/advanced-cache.php';
		if (file_exists($adv_cache_path)
			&& (filesize($adv_cache_path) !== 0 || !is_writable($adv_cache_path))) {
			return false;
		}
		copy(LSWCP_DIR . 'includes/advanced-cache.php', $adv_cache_path);
		include($adv_cache_path);
		$ret = defined('LSCACHE_ADV_CACHE');
		return $ret;
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the LiteSpeed_Cache_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   public
	 */
	public function set_locale(){
		load_plugin_textdomain(self::PLUGIN_NAME, false, 'litespeed-cache/languages/') ;
	}


	/**
	 * Register purge tag for pages with recent posts widget
	 * of the plugin.
	 *
	 * @since    1.0.15
	 * @access   public
	 * @param array $params [wordpress params for widget_posts_args]
	 */
	public function register_tag_widget_recent_posts($params){
		LiteSpeed_Cache_Tags::add_cache_tag(LiteSpeed_Cache_Tags::TYPE_PAGES_WITH_RECENT_POSTS);
		return $params;
	}

	/**
	 * Adds the actions used for setting up cookies on log in/out.
	 *
	 * Also checks if the database matches the rewrite rule.
	 *
	 * @since 1.0.4
	 * @access private
	 * @return boolean True if cookies are bad, false otherwise.
	 */
	private function setup_cookies(){
		$ret = false;
		// Set vary cookie for logging in user, unset for logging out.
		add_action('set_logged_in_cookie', array( $this, 'set_user_cookie'), 10, 5);
		add_action('clear_auth_cookie', array( $this, 'set_user_cookie'), 10, 5);

		if (!$this->config->get_option(LiteSpeed_Cache_Config::OPID_CACHE_COMMENTERS)) {
			// Set vary cookie for commenter.
			add_action('set_comment_cookies', array( $this, 'set_comment_cookie'), 10, 2);
		}
		if (is_multisite()) {
			$options = $this->config->get_site_options();
			if (is_array($options)) {
				$db_cookie = $options[
				LiteSpeed_Cache_Config::OPID_LOGIN_COOKIE];
			}
		}
		else {
			$db_cookie = $this->config->get_option(LiteSpeed_Cache_Config::OPID_LOGIN_COOKIE);
		}

		if (!isset($_SERVER[self::LSCOOKIE_VARY_NAME])) {
			if (!empty($db_cookie)) {
				$ret = true;
				if (is_multisite() ? is_network_admin() : is_admin()) {
					LiteSpeed_Cache_Admin_Display::show_error_cookie();
				}
			}
			$this->current_vary = self::LSCOOKIE_DEFAULT_VARY;
			return $ret;
		}
		elseif (empty($db_cookie)) {
			$this->current_vary = self::LSCOOKIE_DEFAULT_VARY;
			return $ret;
		}
		// beyond this point, need to do more processing.
		$vary_arr = explode(',', $_SERVER[self::LSCOOKIE_VARY_NAME]);

		if (in_array($db_cookie, $vary_arr)) {
			$this->current_vary = $db_cookie;
			return $ret;
		}
		elseif ((is_multisite() ? is_network_admin() : is_admin())) {
			LiteSpeed_Cache_Admin_Display::show_error_cookie();
		}
		$ret = true;
		$this->current_vary = self::LSCOOKIE_DEFAULT_VARY;
		return $ret;
	}

	/**
	 * Do the action of setting the vary cookie.
	 *
	 * Since we are using bitwise operations, if the resulting cookie has
	 * value zero, we need to set the expire time appropriately.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param integer $update_val The value to update.
	 * @param integer $expire Expire time.
	 * @param boolean $ssl True if ssl connection, false otherwise.
	 * @param boolean $httponly True if the cookie is for http requests only, false otherwise.
	 */
	private function do_set_cookie($update_val, $expire, $ssl = false, $httponly = false){
		$curval = 0;
		if (isset($_COOKIE[$this->current_vary]))
		{
			$curval = intval($_COOKIE[$this->current_vary]);
		}

		// not, remove from curval.
		if ($update_val < 0) {
			// If cookie will no longer exist, delete the cookie.
			if (($curval == 0) || ($curval == (~$update_val))) {
				// Use a year in case of bad local clock.
				$expire = time() - 31536001;
			}
			$curval &= $update_val;
		}
		else { // add to curval.
			$curval |= $update_val;
		}
		setcookie($this->current_vary, $curval, $expire, COOKIEPATH,
				COOKIE_DOMAIN, $ssl, $httponly);
	}

	/**
	 * Sets cookie denoting logged in/logged out.
	 *
	 * This will notify the server on next page request not to serve from cache.
	 *
	 * @since 1.0.1
	 * @access public
	 * @param mixed $logged_in_cookie
	 * @param string $expire Expire time.
	 * @param integer $expiration Expire time.
	 * @param integer $user_id The user's id.
	 * @param string $action Whether the user is logging in or logging out.
	 */
	public function set_user_cookie($logged_in_cookie = false, $expire = ' ',
					$expiration = 0, $user_id = 0, $action = 'logged_out'){
		if ($action == 'logged_in') {
			$this->do_set_cookie(self::LSCOOKIE_VARY_LOGGED_IN, $expire, is_ssl(), true);
		}
		else {
			$this->do_set_cookie(~self::LSCOOKIE_VARY_LOGGED_IN,
					time() + apply_filters( 'comment_cookie_lifetime', 30000000 ));
		}
	}

	/**
	 * Sets a cookie that marks the user as a commenter.
	 *
	 * This will notify the server on next page request not to serve
	 * from cache if that setting is enabled.
	 *
	 * @since 1.0.4
	 * @access public
	 * @param mixed $comment Comment object
	 * @param mixed $user The visiting user object.
	 */
	public function set_comment_cookie($comment, $user){
		if ( $user->exists() ) {
			return;
		}
		$comment_cookie_lifetime = time() + apply_filters( 'comment_cookie_lifetime', 30000000 );
		$this->do_set_cookie(self::LSCOOKIE_VARY_COMMENTER, $comment_cookie_lifetime);
	}

	/**
	 * Adds new purge tags to the array of purge tags for the request.
	 *
	 * @since 1.0.1
	 * @access private
	 * @param mixed $tags Tags to add to the list.
	 * @param boolean $is_public Whether to add public or private purge tags.
	 */
	private function add_purge_tags($tags, $is_public = true){
		//TODO: implement private tag add
		if (is_array($tags)) {
			$this->pub_purge_tags = array_merge($this->pub_purge_tags, $tags);
		}
		else {
			$this->pub_purge_tags[] = $tags;
		}
		$this->pub_purge_tags = array_unique($this->pub_purge_tags);
	}

	/**
	 * Alerts LiteSpeed Web Server to purge all pages.
	 *
	 * For multisite installs, if this is called by a site admin (not network admin),
	 * it will only purge all posts associated with that site.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function purge_all(){
		$this->add_purge_tags('*');
	}

	/**
	 * Alerts LiteSpeed Web Server to purge the front page.
	 *
	 * @since    1.0.3
	 * @access   public
	 */
	public function purge_front(){
		$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_FRONTPAGE);
	}

	/**
	 * Alerts LiteSpeed Web Server to purge pages.
	 *
	 * @since    1.0.15
	 * @access   public
	 */
	public function purge_pages(){
		$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_PAGES);
	}

	/**
	 * Alerts LiteSpeed Web Server to purge error pages.
	 *
	 * @since    1.0.14
	 * @access   public
	 */
	public function purge_errors(){
		$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_ERROR);
		if (!isset($_POST[LiteSpeed_Cache_Config::OPTION_NAME])) {
			return;
		}
		$input = $_POST[LiteSpeed_Cache_Config::OPTION_NAME];
		if (isset($input['include_403'])) {
			$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_ERROR . '403');
		}
		if (isset($input['include_404'])) {
			$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_ERROR . '404');
		}
		if (isset($input['include_500'])) {
			$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_ERROR . '500');
		}
	}

	/**
	 * The purge by callback used to purge a list of tags.
	 *
	 * @access public
	 * @since 1.0.15
	 * @param string $tags A comma delimited list of tags.
	 */
	public function purgeby_cb($tags){
		$tag_arr = explode(',', $tags);
		self::add_purge_tags($tag_arr);
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected category pages.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The category slug.
	 * @param string $key Unused.
	 */
	public function purgeby_cat_cb($value, $key){
		$val = trim($value);
		if (empty($val)) {
			return;
		}
		if (preg_match('/^[a-zA-Z0-9-]+$/', $val) == 0) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_CAT_INV);
			return;
		}
		$cat = get_category_by_slug($val);
		if ($cat == false) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_CAT_DNE, $val);
			return;
		}

		LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf(__('Purge category %s', 'litespeed-cache'), $val));

		LiteSpeed_Cache_Tags::add_purge_tag(LiteSpeed_Cache_Tags::TYPE_ARCHIVE_TERM . $cat->term_id);
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected post IDs.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The post ID.
	 * @param string $key Unused.
	 */
	public function purgeby_pid_cb($value, $key){
		$val = trim($value);
		if (empty($val)) {
			return;
		}
		if (!is_numeric($val)) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_PID_NUM, $val);
			return;
		}
		elseif (get_post_status($val) !== 'publish') {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_PID_DNE, $val);
			return;
		}
		LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf(__('Purge Post ID %s', 'litespeed-cache'), $val));

		LiteSpeed_Cache_Tags::add_purge_tag(LiteSpeed_Cache_Tags::TYPE_POST . $val);
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected tag pages.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The tag slug.
	 * @param string $key Unused.
	 */
	public function purgeby_tag_cb($value, $key){
		$val = trim($value);
		if (empty($val)) {
			return;
		}
		if (preg_match('/^[a-zA-Z0-9-]+$/', $val) == 0) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_TAG_INV);
			return;
		}
		$term = get_term_by('slug', $val, 'post_tag');
		if ($term == 0) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_TAG_DNE, $val);
			return;
		}

		LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf(__('Purge tag %s', 'litespeed-cache'), $val));

		LiteSpeed_Cache_Tags::add_purge_tag(LiteSpeed_Cache_Tags::TYPE_ARCHIVE_TERM . $term->term_id);
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected urls.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value A url to purge.
	 * @param string $key Unused.
	 */
	public function purgeby_url_cb($value, $key){
		$val = trim($value);
		if (empty($val)) {
			return;
		}

		if (strpos($val, '<') !== false) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_URL_BAD);
			return;
		}

		$hash = self::get_uri_hash($val);

		if ($hash === false) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_URL_INV, $val);
			return;
		}

		LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf(__('Purge url %s', 'litespeed-cache'), $val));

		LiteSpeed_Cache_Tags::add_purge_tag(LiteSpeed_Cache_Tags::TYPE_URL . $hash);
		return;
	}

	/**
	 * Purge a list of pages when selected by admin. This method will
	 * look at the post arguments to determine how and what to purge.
	 *
	 * @since 1.0.7
	 * @access public
	 */
	public function purge_list(){
		if ( !isset($_POST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT])
			|| !isset($_POST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_LIST])
		) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGE_FORM);
			return;
		}
		$sel =  $_POST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT];
		$list_buf = $_POST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_LIST];
		if (empty($list_buf)) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_EMPTY);
			return;
		}
		$list = explode("\n", $list_buf);
		switch($sel) {
			case LiteSpeed_Cache_Admin_Display::PURGEBY_CAT:
				$cb = 'purgeby_cat_cb';
				break;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_PID:
				$cb = 'purgeby_pid_cb';
				break;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_TAG:
				$cb = 'purgeby_tag_cb';
				break;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_URL:
				$cb = 'purgeby_url_cb';
				break;
			default:
				LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_BAD);
				return;
		}
		array_walk($list, Array($this, $cb));

		// for redirection
		$_GET[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT] = $sel;
	}

	/**
	 * Purges a post on update.
	 *
	 * This function will get the relevant purge tags to add to the response
	 * as well.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param integer $id The post id to purge.
	 */
	public function purge_post( $id ){
		$post_id = intval($id);
		// ignore the status we don't care
		if ( ! in_array(get_post_status($post_id), array( 'publish', 'trash', 'private' )) ) {
			return ;
		}

		$purge_tags = $this->get_purge_tags($post_id) ;
		if ( empty($purge_tags) ) {
			return;
		}
		if ( in_array('*', $purge_tags) ) {
			$this->add_purge_tags('*');
		}
		else {
			$this->add_purge_tags($purge_tags);
		}
		$this->cachectrl |= self::CACHECTRL_STALE;
		// $this->send_purge_headers();
	}

	/**
	 * Purge a single post.
	 *
	 * If a third party plugin needs to purge a single post, it can send
	 * a purge tag using this function.
	 *
	 * @since 1.0.1
	 * @access public
	 * @param integer $id The post id to purge.
	 */
	public function purge_single_post($id){
		$post_id = intval($id);
		if ( ! in_array(get_post_status($post_id), array( 'publish', 'trash' )) ) {
			return ;
		}
		$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_POST . $post_id);
		// $this->send_purge_headers();
	}

	/**
	 * Purges feeds on comment count update.
	 *
	 * @since 1.0.9
	 * @access public
	 */
	public function purge_feeds(){
		if ($this->config->get_option(LiteSpeed_Cache_Config::OPID_FEED_TTL) > 0) {
			$this->add_purge_tags(LiteSpeed_Cache_Tags::TYPE_FEED);
		}
	}

	/**
	 * Checks if the user is logged in. If the user is logged in, does an
	 * additional check to make sure it's using the correct login cookie.
	 *
	 * @return boolean True if logged in, false otherwise.
	 */
	private function check_user_logged_in(){
		if (!is_user_logged_in()) {
			// If the cookie is set, unset it.
			if ((isset($_COOKIE)) && (isset($_COOKIE[$this->current_vary]))
				&& (intval($_COOKIE[$this->current_vary])
					& self::LSCOOKIE_VARY_LOGGED_IN)) {
				$this->do_set_cookie(~self::LSCOOKIE_VARY_LOGGED_IN,
					time() + apply_filters( 'comment_cookie_lifetime', 30000000 ));
				$_COOKIE[$this->current_vary] &= ~self::LSCOOKIE_VARY_LOGGED_IN;
			}
			return false;
		}
		elseif (!isset($_COOKIE[$this->current_vary])) {
			$this->do_set_cookie(self::LSCOOKIE_VARY_LOGGED_IN,
					time() + 2 * DAY_IN_SECONDS, is_ssl(), true);
		}
		return true;
	}

	/**
	 * Check if the user accessing the page has the commenter cookie.
	 *
	 * If the user does not want to cache commenters, just check if user is commenter.
	 * Otherwise if the vary cookie is set, unset it. This is so that when
	 * the page is cached, the page will appear as if the user was a normal user.
	 * Normal user is defined as not a logged in user and not a commenter.
	 *
	 * @since 1.0.4
	 * @access private
	 * @return boolean True if do not cache for commenters and user is a commenter. False otherwise.
	 */
	private function check_cookies(){
		if (!$this->config->get_option(LiteSpeed_Cache_Config::OPID_CACHE_COMMENTERS))
		{
			// If do not cache commenters, check cookie for commenter value.
			if ((isset($_COOKIE[$this->current_vary]))
					&& ($_COOKIE[$this->current_vary] & self::LSCOOKIE_VARY_COMMENTER)) {
				return true;
			}
			// If wp commenter cookie exists, need to set vary and do not cache.
			foreach($_COOKIE as $cookie_name => $cookie_value) {
				if ((strlen($cookie_name) >= 15)
						&& (strncmp($cookie_name, 'comment_author_', 15) == 0)) {
					$user = wp_get_current_user();
					$this->set_comment_cookie(NULL, $user);
					return true;
				}
			}
			return false;
		}

		// If vary cookie is set, need to change the value.
		if (isset($_COOKIE[$this->current_vary])) {
			$this->do_set_cookie(~self::LSCOOKIE_VARY_COMMENTER, 14 * DAY_IN_SECONDS);
			unset($_COOKIE[$this->current_vary]);
		}

		// If cache commenters, unset comment cookies for caching.
		foreach($_COOKIE as $cookie_name => $cookie_value) {
			if ((strlen($cookie_name) >= 15)
					&& (strncmp($cookie_name, 'comment_author_', 15) == 0)) {
				unset($_COOKIE[$cookie_name]);
			}
		}
		return false;
	}

	/**
	 * Check admin configuration to see if the uri accessed is excluded from cache.
	 *
	 * @since 1.0.1
	 * @access private
	 * @param array $excludes_list List of excluded URIs
	 * @return boolean True if excluded, false otherwise.
	 */
	private function is_uri_excluded($excludes_list){
		$uri = esc_url($_SERVER["REQUEST_URI"]);
		$uri_len = strlen( $uri ) ;
		if (is_multisite()) {
			$blog_details = get_blog_details(get_current_blog_id());
			$blog_path = $blog_details->path;
			$blog_path_len = strlen($blog_path);
			if (($uri_len >= $blog_path_len)
				&& (strncmp($uri, $blog_path, $blog_path_len) == 0)) {
				$uri = substr($uri, $blog_path_len - 1);
				$uri_len = strlen( $uri ) ;
			}
		}
		foreach( $excludes_list as $excludes_rule ){
			$rule_len = strlen( $excludes_rule );
			if (($excludes_rule[$rule_len - 1] == '$')) {
				if ($uri_len != (--$rule_len)) {
					continue;
				}
			}
			elseif ( $uri_len < $rule_len ) {
				continue;
			}

			if ( strncmp( $uri, $excludes_rule, $rule_len ) == 0 ){
				return true ;
			}
		}
		return false;
	}

	/**
	 * Check if a page is cacheable.
	 *
	 * This will check what we consider not cacheable as well as what
	 * third party plugins consider not cacheable.
	 *
	 * @since 1.0.0
	 * @access private
	 * @return boolean True if cacheable, false otherwise.
	 */
	private function is_cacheable(){
		// logged_in users already excluded, no hook added
		$method = $_SERVER["REQUEST_METHOD"] ;
		$conf = $this->config;

		if ( 'GET' !== $method ) {
			return $this->no_cache_for('not GET method') ;
		}

		if (($conf->get_option(LiteSpeed_Cache_Config::OPID_FEED_TTL) === 0)
			&& (is_feed())) {
			return $this->no_cache_for('feed') ;
		}

		if ( is_trackback() ) {
			return $this->no_cache_for('trackback') ;
		}

		if (($conf->get_option(LiteSpeed_Cache_Config::OPID_404_TTL) === 0)
			&& (is_404())) {
			return $this->no_cache_for('404 pages') ;
		}

		if ( is_search() ) {
			return $this->no_cache_for('search') ;
		}

//		if ( !defined('WP_USE_THEMES') || !WP_USE_THEMES ) {
//			return $this->no_cache_for('no theme used') ;
//		}

		$cacheable = apply_filters('litespeed_cache_is_cacheable', true);
		if (!$cacheable) {
			global $wp_filter;
			if ((!LiteSpeed_Cache_Log::get_enabled())
				|| (empty($wp_filter['litespeed_cache_is_cacheable']))) {
				return $this->no_cache_for(
					'Third Party Plugin determined not cacheable.');
			}
			$funcs = array();
			foreach ($wp_filter['litespeed_cache_is_cacheable'] as $hook_level) {
				foreach ($hook_level as $func=>$params) {
					$funcs[] = $func;
				}
			}
			$this->no_cache_for('One of the following functions '
				. "determined that this page is not cacheable:\n\t\t"
				. implode("\n\t\t", $funcs));
			return false;
		}

		$excludes = $conf->get_option(LiteSpeed_Cache_Config::OPID_EXCLUDES_URI);
		if (( ! empty($excludes))
			&& ( $this->is_uri_excluded(explode("\n", $excludes))))
		{
			return $this->no_cache_for('Admin configured URI Do not cache: '
					. $_SERVER['REQUEST_URI']);
		}

		$excludes = $conf->get_option(LiteSpeed_Cache_Config::OPID_EXCLUDES_CAT);
		if (( ! empty($excludes))
			&& (has_category(explode(',', $excludes)))) {
			return $this->no_cache_for('Admin configured Category Do not cache.');
		}

		$excludes = $conf->get_option(LiteSpeed_Cache_Config::OPID_EXCLUDES_TAG);
		if (( ! empty($excludes))
			&& (has_tag(explode(',', $excludes)))) {
			return $this->no_cache_for('Admin configured Tag Do not cache.');
		}

		$excludes = $conf->get_option(LiteSpeed_Cache_Config::ID_NOCACHE_COOKIES);
		if ((!empty($excludes)) && (!empty($_COOKIE))) {
			$exclude_list = explode('|', $excludes);

			foreach( $_COOKIE as $key=>$val) {
				if (in_array($key, $exclude_list)) {
					return $this->no_cache_for('Admin configured Cookie Do not cache.');
				}
			}
		}

		$excludes = $conf->get_option(LiteSpeed_Cache_Config::ID_NOCACHE_USERAGENTS);
		if ((!empty($excludes)) && (isset($_SERVER['HTTP_USER_AGENT']))) {
			$pattern = '/' . $excludes . '/';
			$nummatches = preg_match($pattern, $_SERVER['HTTP_USER_AGENT']);
			if ($nummatches) {
					return $this->no_cache_for('Admin configured User Agent Do not cache.');
			}
		}

		return true;
	}

	/**
	 * Check if the page returns 403 and 500 errors.
	 *
	 * @since 1.0.13.1
	 * @access public
	 * @param $header, $code.
	 * @return $eeror_status.
	 */
	public function check_error_codes($header, $code){
		$ttl_403 = $this->config->get_option(LiteSpeed_Cache_Config::OPID_403_TTL);
		$ttl_500 = $this->config->get_option(LiteSpeed_Cache_Config::OPID_500_TTL);
		if ($code == 403) {
			if ($ttl_403 <= 30) {
				LiteSpeed_Cache_Tags::set_noncacheable();
			}
			else {
				$this->error_status = $code;
			}
		}
		elseif ($code >= 500 && $code < 600) {
			if ($ttl_500 <= 30) {
				LiteSpeed_Cache_Tags::set_noncacheable();
			}
		}
		elseif ($code > 400) {
			$this->error_status = $code;
		}
		return $this->error_status;
	}

	/**
	 * Write a debug message for if a page is not cacheable.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $reason An explanation for why the page is not cacheable.
	 * @return boolean Return false.
	 */
	private function no_cache_for( $reason ){
		if (LiteSpeed_Cache_Log::get_enabled()) {
			LiteSpeed_Cache_Log::push('Do not cache - ' . $reason);
		}
		return false ;
	}

	/**
	 * Check if the post is cacheable. If so, set the cacheable member variable.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function check_cacheable(){
		if ((LiteSpeed_Cache_Tags::is_noncacheable() == false)
			&& ($this->is_cacheable())) {
			$this->cachectrl = self::CACHECTRL_CACHE;
		}
	}

	/**
	 * Check if the login page is cacheable.
	 * If not, unset the cacheable member variable.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function check_login_cacheable(){
		if ($this->config->get_option(LiteSpeed_Cache_Config::OPID_CACHE_LOGIN) === false) {
			return;
		}
		$this->check_cacheable();
		if ($this->cachectrl !== self::CACHECTRL_CACHE) {
			return;
		}
		if (!empty($_GET)) {

			if (LiteSpeed_Cache_Log::get_enabled()) {
				$this->no_cache_for('Not a get request');
			}
			$this->cachectrl = self::CACHECTRL_NOCACHE;
			return;
		}

		LiteSpeed_Cache_Tags::add_cache_tag(LiteSpeed_Cache_Tags::TYPE_LOGIN);

		$list = headers_list();
		if (empty($list)) {
			return;
		}
		foreach ($list as $hdr) {
			if (strncasecmp($hdr, 'set-cookie:', 11) == 0) {
				$cookie = substr($hdr, 12);
				@header('lsc-cookie: ' . $cookie, false);
			}
		}
		return;
	}

	/**
	 * After a LSCWP_CTRL action, need to redirect back to the same page
	 * without the nonce and action in the query string.
	 *
	 * @since 1.0.12
	 * @access public
	 * @global string $pagenow
	 */
	public function admin_ctrl_redirect(){
		global $pagenow;
		$qs = '';

		if (!empty($_GET)) {
			if (isset($_GET[LiteSpeed_Cache::ACTION_KEY])) {
				unset($_GET[LiteSpeed_Cache::ACTION_KEY]);
			}
			if (isset($_GET[LiteSpeed_Cache::NONCE_NAME])) {
				unset($_GET[LiteSpeed_Cache::NONCE_NAME]);
			}
			if (!empty($_GET)) {
				$qs = '?' . http_build_query($_GET);
			}
		}
		if (is_network_admin()) {
			$url = network_admin_url($pagenow . $qs);
		}
		else {
			$url = admin_url($pagenow . $qs);
		}
		wp_redirect($url);
		exit();
	}

	/**
	 * Gathers all the purge headers.
	 *
	 * This will collect all site wide purge tags as well as
	 * third party plugin defined purge tags.
	 *
	 * @since 1.0.1
	 * @access private
	 * @param boolean $stale Whether to add header as a stale header or not.
	 * @return string The purge header
	 */
	private function build_purge_headers($stale){
		$cache_purge_header = LiteSpeed_Cache_Tags::HEADER_PURGE . ': ';
		$purge_tags = array_merge($this->pub_purge_tags,
				LiteSpeed_Cache_Tags::get_purge_tags());
		$purge_tags = array_unique($purge_tags);

		if (empty($purge_tags)) {
			return '';
		}

		$prefix = $this->config->get_option(
			LiteSpeed_Cache_Config::OPID_TAG_PREFIX);
		if (empty($prefix)) {
			$prefix = '';
		}

		if (!in_array('*', $purge_tags )) {
			$tags = array_map(array($this,'prefix_apply'), $purge_tags);
		}
		elseif (isset($_POST['clearcache'])) {
			$tags = array('*');
		}
		// Would only use multisite and network admin except is_network_admin
		// is false for ajax calls, which is used by wordpress updates v4.6+
		elseif ((is_multisite()) && ((is_network_admin())
			|| ((defined('DOING_AJAX'))
					&& ((check_ajax_referer('updates', false, false))
						|| (check_ajax_referer('litespeed-purgeall-network',
							false, false)))))) {
			$blogs = self::get_network_ids();
			if (empty($blogs)) {
				if (LiteSpeed_Cache_Log::get_enabled()) {
					LiteSpeed_Cache_Log::push('blog list is empty');
				}
				return '';
			}
			$tags = array();
			foreach ($blogs as $blog_id) {
				$tags[] = sprintf('%sB%s_', $prefix, $blog_id);
			}
		}
		else {
			$tags = array($prefix . 'B' . get_current_blog_id() . '_');
		}

		if ($stale) {
			$cache_purge_header .= 'stale,';
		}

		$cache_purge_header .= 'tag=' . implode(',', $tags);
		return $cache_purge_header;
		// TODO: private cache headers
//		$cache_purge_header = LiteSpeed_Cache_Tags::HEADER_PURGE
//				. ': private,tag=' . implode(',', $this->ext_purge_private_tags);
//		@header($cache_purge_header, false);
	}

	/**
	 * Builds the vary header.
	 *
	 * Currently, this only checks post passwords.
	 *
	 * @since 1.0.13
	 * @access private
	 * @global $post
	 * @return mixed false if the user has the postpass cookie. Empty string
	 * if the post is not password protected. Vary header otherwise.
	 */
	private function build_vary_headers(){
		global $post;
		$tp_cookies = LiteSpeed_Cache_Tags::get_vary_cookies();
		if (!empty($post->post_password)) {
			if (isset($_COOKIE['wp-postpass_' . COOKIEHASH])) {
				// If user has password cookie, do not cache
				return false;
			}
			else {
				$tp_cookies[] = 'cookie=wp-postpass_' . COOKIEHASH;
			}
		}

		if (empty($tp_cookies)) {
			return '';
		}
		return LiteSpeed_Cache_Tags::HEADER_CACHE_VARY
		. ': ' . implode(',', $tp_cookies);
	}

	/**
	 * The mode determines if the page is cacheable. This function filters
	 * out the possible show header admin control.
	 *
	 * @since 1.0.7
	 * @access private
	 * @param boolean $showhdr Whether the show header command was selected.
	 * @param boolean $stale Whether to make the purge headers stale.
	 * @return integer The integer corresponding to the selected
	 * cache control value.
	 */
	private function validate_mode(&$showhdr, &$stale){
		$mode = $this->cachectrl;
		if ($mode & self::CACHECTRL_SHOWHEADERS) {
			$showhdr = true;
			$mode &= ~self::CACHECTRL_SHOWHEADERS;
		}

		if ($mode & self::CACHECTRL_STALE) {
			$stale = true;
			$mode &= ~self::CACHECTRL_STALE;
		}

		if ($mode != self::CACHECTRL_CACHE) {
			return $mode;
		}
		elseif ((is_admin()) || (is_network_admin())) {
			return self::CACHECTRL_NOCACHE;
		}

		if (((defined('LSCACHE_NO_CACHE')) && (constant('LSCACHE_NO_CACHE')))
			|| (LiteSpeed_Cache_Tags::is_noncacheable())) {
			return self::CACHECTRL_NOCACHE;
		}

		if ($this->config->get_option(
				LiteSpeed_Cache_Config::OPID_MOBILEVIEW_ENABLED) == false) {
			return (LiteSpeed_Cache_Tags::is_mobile() ? self::CACHECTRL_NOCACHE
														: $mode);
		}

		if ((isset($_SERVER['LSCACHE_VARY_VALUE']))
			&& ($_SERVER['LSCACHE_VARY_VALUE'] === 'ismobile')) {
			if ((!wp_is_mobile()) && (!LiteSpeed_Cache_Tags::is_mobile())) {
				return self::CACHECTRL_NOCACHE;
			}
		}
		elseif ((wp_is_mobile()) || (LiteSpeed_Cache_Tags::is_mobile())) {
			return self::CACHECTRL_NOCACHE;
		}

		return $mode;
	}

	/**
	 * Send out the LiteSpeed Cache headers. If show headers is true,
	 * will send out debug header.
	 *
	 * @since 1.0.7
	 * @access private
	 * @param boolean $showhdr True to show debug header, false if real headers.
	 * @param string $cache_ctrl The cache control header to send out.
	 * @param string $purge_hdr The purge tag header to send out.
	 * @param string $cache_hdr The cache tag header to send out.
	 * @param string $vary_hdr The cache vary header to send out.
	 */
	private function header_out($showhdr, $cache_ctrl, $purge_hdr,
	                            $cache_hdr = '', $vary_hdr = ''){
		$hdr_content = array();
		if ((!is_null($cache_ctrl)) && (!empty($cache_ctrl))) {
			$hdr_content[] = $cache_ctrl;
		}
		if ((!is_null($purge_hdr)) && (!empty($purge_hdr))) {
			$hdr_content[] = $purge_hdr;
		}
		if ((!is_null($cache_hdr)) && (!empty($cache_hdr))) {
			$hdr_content[] = $cache_hdr;
		}
		if ((!is_null($vary_hdr)) && (!empty($vary_hdr))) {
			$hdr_content[] = $vary_hdr;
		}

		if (!empty($hdr_content)) {
			if ($showhdr) {
				@header(LiteSpeed_Cache_Tags::HEADER_DEBUG . ': ' . implode('; ', $hdr_content));
			}
			else {
				foreach($hdr_content as $hdr) {
					@header($hdr);
				}
			}
		}

		if(!defined('DOING_AJAX')){
			echo '<!-- Page generated by LiteSpeed Cache on '.date('Y-m-d H:i:s').' -->';
		}
		if (LiteSpeed_Cache_Log::get_enabled()) {
			if($cache_hdr){
				LiteSpeed_Cache_Log::push($cache_hdr);
				if(!defined('DOING_AJAX')){
					echo "\n<!-- ".$cache_hdr." -->";
				}
			}
			if($cache_ctrl) {
				LiteSpeed_Cache_Log::push($cache_ctrl);
				if(!defined('DOING_AJAX')){
					echo "\n<!-- ".$cache_ctrl." -->";
				}
			}
			if($purge_hdr) {
				LiteSpeed_Cache_Log::push($purge_hdr);
				if(!defined('DOING_AJAX')){
					echo "\n<!-- ".$purge_hdr." -->";
				}
			}
			LiteSpeed_Cache_Log::push("End response.\n");
		}
	}

	/**
	 * Sends the headers out at the end of processing the request.
	 *
	 * This will send out all LiteSpeed Cache related response headers
	 * needed for the post.
	 *
	 * @since 1.0.5
	 * @access public
	 */
	public function send_headers(){
		$cache_control_header = '';
		$cache_tag_header = '';
		$vary_headers = '';
		$cache_tags = null;
		$showhdr = false;
		$stale = false;
		do_action('litespeed_cache_add_purge_tags');

		$mode = $this->validate_mode($showhdr, $stale);

		if ($mode != self::CACHECTRL_NOCACHE) {
			do_action('litespeed_cache_add_cache_tags');
			$vary_headers = $this->build_vary_headers();
			$cache_tags = $this->get_cache_tags();
			if ($mode === self::CACHECTRL_CACHE) {
				$cache_tags[] = ''; //add blank entry to add blog tag.
			}
		}

		if (empty($cache_tags) || ($vary_headers === false)) {
			$cache_control_header =
					LiteSpeed_Cache_Tags::HEADER_CACHE_CONTROL . ': no-cache' /*. ',esi=on'*/ ;
			$purge_headers = $this->build_purge_headers($stale);
			$this->header_out($showhdr, $cache_control_header, $purge_headers);
			return;
		}
		$prefix_tags = array_map(array($this,'prefix_apply'), $cache_tags);

		switch ($mode) {
			case self::CACHECTRL_CACHE:
				$feed_ttl = $this->config->get_option(LiteSpeed_Cache_Config::OPID_FEED_TTL);
				$ttl_403 = $this->config->get_option(LiteSpeed_Cache_Config::OPID_403_TTL);
				$ttl_404 = $this->config->get_option(LiteSpeed_Cache_Config::OPID_404_TTL);
				$ttl_500 = $this->config->get_option(LiteSpeed_Cache_Config::OPID_500_TTL);
				if ((LiteSpeed_Cache_Tags::get_use_frontpage_ttl())
					|| (is_front_page())){
					$ttl = $this->config->get_option(LiteSpeed_Cache_Config::OPID_FRONT_PAGE_TTL);
				}
				elseif ((is_feed()) && ($feed_ttl > 0)) {
					$ttl = $feed_ttl;
				}
				elseif ((is_404()) && ($ttl_404 > 0)) {
					$ttl = $ttl_404;
				}
				elseif ($this->error_status === 403) {
					$ttl = $ttl_403;
				}
				elseif ($this->error_status >= 500) {
					$ttl = $ttl_500;
				}
				else {
					$ttl = $this->config->get_option(LiteSpeed_Cache_Config::OPID_PUBLIC_TTL) ;
				}
				$cache_control_header = LiteSpeed_Cache_Tags::HEADER_CACHE_CONTROL
						. ': public,max-age=' . $ttl /*. ',esi=on'*/ ;
				$cache_tag_header = LiteSpeed_Cache_Tags::HEADER_CACHE_TAG
					. ': ' . implode(',', $prefix_tags) ;
				break;
			case self::CACHECTRL_PURGESINGLE:
				$cache_tags = $cache_tags[0];
				// fall through
			case self::CACHECTRL_PURGE:
				$cache_control_header =
					LiteSpeed_Cache_Tags::HEADER_CACHE_CONTROL . ': no-cache' /*. ',esi=on'*/ ;
				LiteSpeed_Cache_Tags::add_purge_tag($cache_tags);
				break;

		}
		$purge_headers = $this->build_purge_headers($stale);
		$this->header_out($showhdr, $cache_control_header, $purge_headers,
				$cache_tag_header, $vary_headers);
	}

	/**
	  * Callback function that applies a prefix to cache/purge tags.
	  *
	  * The first call to this method will build the prefix. Subsequent calls
	  * will use the already set prefix.
	  *
	  * @since 1.0.9
	  * @access private
	  * @staticvar string $prefix The prefix to use for each tag.
	  * @param string $tag The tag to prefix.
	  * @return string The amended tag.
	  */
	private function prefix_apply($tag){
		static $prefix = null;
		if (is_null($prefix)) {
			$prefix = $this->config->get_option(
				LiteSpeed_Cache_Config::OPID_TAG_PREFIX);
			if (empty($prefix)) {
				$prefix = '';
			}
			$prefix .= 'B' . get_current_blog_id() . '_';
		}
		return $prefix . $tag;
	}

	/**
	 * Gets the cache tags to set for the page.
	 *
	 * This includes site wide post types (e.g. front page) as well as
	 * any third party plugin specific cache tags.
	 *
	 * @since 1.0.0
	 * @access private
	 * @return array The list of cache tags to set.
	 */
	private function get_cache_tags(){
		global $post ;
		global $wp_query ;

		$queried_obj_id = get_queried_object_id() ;
		$cache_tags = array();

		$hash = self::get_uri_hash(urldecode($_SERVER['REQUEST_URI']));

		$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_URL . $hash;

		if ( is_front_page() ) {
			$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_FRONTPAGE ;
		}
		elseif ( is_home() ) {
			$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_HOME ;
		}

		if ($this->error_status !== false) {
			$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ERROR . $this->error_status;
		}

		if ( is_archive() ) {
			//An Archive is a Category, Tag, Author, Date, Custom Post Type or Custom Taxonomy based pages.

			if ( is_category() || is_tag() || is_tax() ) {
				$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_TERM . $queried_obj_id ;
			}
			elseif ( is_post_type_archive() ) {
				$post_type = $wp_query->get('post_type') ;
				$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_POSTTYPE . $post_type ;
			}
			elseif ( is_author() ) {
				$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_AUTHOR . $queried_obj_id ;
			}
			elseif ( is_date() ) {
				$date = $post->post_date ;
				$date = strtotime($date) ;
				if ( is_day() ) {
					$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Ymd', $date) ;
				}
				elseif ( is_month() ) {
					$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Ym', $date) ;
				}
				elseif ( is_year() ) {
					$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Y', $date) ;
				}
			}
		}
		elseif ( is_singular() ) {
			//$this->is_singular = $this->is_single || $this->is_page || $this->is_attachment;
			$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_POST . $queried_obj_id ;

			if ( is_page() ) {
				$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_PAGES;
			}
		}
		elseif ( is_feed() ) {
			$cache_tags[] = LiteSpeed_Cache_Tags::TYPE_FEED;
		}

		return array_merge($cache_tags, LiteSpeed_Cache_Tags::get_cache_tags());
	}

	/**
	 * Gets all the purge tags correlated with the post about to be purged.
	 *
	 * If the purge all pages configuration is set, all pages will be purged.
	 *
	 * This includes site wide post types (e.g. front page) as well as
	 * any third party plugin specific post tags.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param integer $post_id The id of the post about to be purged.
	 * @return array The list of purge tags correlated with the post.
	 */
	private function get_purge_tags( $post_id ){
		// If this is a valid post we want to purge the post, the home page and any associated tags & cats
		// If not, purge everything on the site.

		$purge_tags = array() ;
		$config = $this->config ;

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_ALL_PAGES) ) {
			// ignore the rest if purge all
			return array( '*' ) ;
		}

		do_action('litespeed_cache_on_purge_post', $post_id);

		// post
		$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_POST . $post_id ;
		$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_URL
			. self::get_uri_hash(wp_make_link_relative(get_post_permalink($post_id)));

		// for archive of categories|tags|custom tax
		global $post;
		$post = get_post($post_id) ;
		$post_type = $post->post_type ;

		// get adjacent posts id as related post tag
		if($post_type == 'post'){
			$prev_post = get_previous_post();
			$next_post = get_next_post();
			if(!empty($prev_post->ID)) {
				$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_POST . $prev_post->ID;
				if(LiteSpeed_Cache_Log::get_enabled()){
					LiteSpeed_Cache_Log::push('--------purge_tags prev is: '.$prev_post->ID);
				}
			}
			if(!empty($next_post->ID)) {
				$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_POST . $next_post->ID;
				if(LiteSpeed_Cache_Log::get_enabled()){
					LiteSpeed_Cache_Log::push('--------purge_tags next is: '.$next_post->ID);
				}
			}
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_TERM) ) {
			$taxonomies = get_object_taxonomies($post_type) ;
			//LiteSpeed_Cache_Log::push('purge by post, check tax = ' . print_r($taxonomies, true)) ;
			foreach ( $taxonomies as $tax ) {
				$terms = get_the_terms($post_id, $tax) ;
				if ( ! empty($terms) ) {
					foreach ( $terms as $term ) {
						$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_TERM . $term->term_id ;
					}
				}
			}
		}

		if ($config->get_option(LiteSpeed_Cache_Config::OPID_FEED_TTL) > 0) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_FEED;
		}

		// author, for author posts and feed list
		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_AUTHOR) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_AUTHOR . get_post_field('post_author', $post_id) ;
		}

		// archive and feed of post type
		// todo: check if type contains space
		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_POST_TYPE) ) {
			if ( get_post_type_archive_link($post_type) ) {
				$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_POSTTYPE . $post_type ;
			}
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_FRONT_PAGE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_FRONTPAGE ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_HOME_PAGE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_HOME ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_PAGES) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_PAGES ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_PAGES_WITH_RECENT_POSTS) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_PAGES_WITH_RECENT_POSTS ;
		}

		// if configured to have archived by date
		$date = $post->post_date ;
		$date = strtotime($date) ;

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_DATE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Ymd', $date) ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_MONTH) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Ym', $date) ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_YEAR) ) {
			$purge_tags[] = LiteSpeed_Cache_Tags::TYPE_ARCHIVE_DATE . date('Y', $date) ;
		}

		return array_unique($purge_tags) ;
	}

	/**
	 * Will get a hash of the URI. Removes query string and appends a '/' if
	 * it is missing.
	 *
	 * @since 1.0.12
	 * @access private
	 * @param string $uri The uri to get the hash of.
	 * @return bool|string False on input error, hash otherwise.
	 */
	private static function get_uri_hash($uri){
		$no_qs = strtok($uri, '?');
		if (empty($no_qs)) {
			return false;
		}
		$slashed = trailingslashit($no_qs);
		return md5($slashed);
	}


	/**
	 * Execute cron
	 * todo: move to admin class with register_activation()
	 *
	 * @since 1.0.16
	 * @access public
	 */
	public function scheduleCron() {
		// todo: check if need to move this to init()
		add_filter('cron_schedules', array($this, 'lscacheCronTagReg'));

		$options = $this->config->get_options();

		$id = LiteSpeed_Cache_Config::CRWL_CRON_ACTIVE;
		$active = @$options[$id];

		$id = LiteSpeed_Cache_Config::CRWL_CRON_INTERVAL;
		$interval = @$options[$id];

		$hookname = 'litespeedCrawlTrigger';
		if(($active == 1) && ($interval > 0)){
			if( !wp_next_scheduled( $hookname ) ) {
				wp_schedule_event( time(), 'lscacheCronTag', $hookname );
			}
		}
	}

	/**
	 * Register cron interval
	 *
	 * @since 1.0.16
	 * @access public
	 */
	public function lscacheCronTagReg($schedules) {
		$wp_schedules = wp_get_schedules();

		if(!array_key_exists('lscacheCronTag', $wp_schedules)){
			$options = $this->config->get_options();

			$id = LiteSpeed_Cache_Config::CRWL_CRON_INTERVAL;
			$interval = $options[$id];
		
			$schedules['lscacheCronTag'] = array(
				'interval' => $interval, 
				'display'  => __( 'LSCache Custom Cron', 'litespeed-cache' ),
			);
		}
		return $schedules;
	}

}
