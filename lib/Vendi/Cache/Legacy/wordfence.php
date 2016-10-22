<?php

namespace Vendi\Cache\Legacy;

use Vendi\Cache\cache_settings;

class wordfence
{
    private static $runInstallCalled = false;

    private static $vwc_cache_settings;

    public static function get_vwc_cache_settings()
    {
        if ( ! self::$vwc_cache_settings)
        {
            self::$vwc_cache_settings = new cache_settings();
        }

        return self::$vwc_cache_settings;

    }

    public static function installPlugin() {
        self::runInstall();
        //Used by MU code below
        update_option('wordfenceActivated', 1);
    }
    public static function uninstallPlugin() {
        //Check if caching is enabled and if it is, disable it and fix the .htaccess file.
        $cacheType = self::get_vwc_cache_settings()->get_cache_mode();
        if ($cacheType == cache_settings::CACHE_MODE_ENHANCED) {
            wfCache::addHtaccessCode('remove');
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_OFF);

            //We currently don't clear the cache when plugin is disabled because it will take too long if done synchronously and won't work because plugin is disabled if done asynchronously.
            //wfCache::scheduleCacheClear();
        } else if ($cacheType == cache_settings::CACHE_MODE_PHP) {
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_OFF);
        }

        //Used by MU code below
        update_option('wordfenceActivated', 0);

        cache_settings::uninstall();
    }

    public static function runInstall() {
        if (self::$runInstallCalled) { return; }
        self::$runInstallCalled = true;
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        $previous_version = get_option('vendi_cache_version', '0.0.0');
        update_option('vendi_cache_version', VENDI_CACHE_VERSION); //In case we have a fatal error we don't want to keep running install.
        //EVERYTHING HERE MUST BE IDEMPOTENT

        if (self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_PHP || self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_ENHANCED) {
            wfCache::removeCacheDirectoryHtaccess();
        }

        //Must be the final line
    }
    public static function install_actions() {
        register_activation_hook(VENDI_CACHE_FCPATH, array(__CLASS__, 'installPlugin'));
        register_deactivation_hook(VENDI_CACHE_FCPATH, array(__CLASS__, 'uninstallPlugin'));

        $versionInOptions = get_option('vendi_cache_version', false);
        if (( ! $versionInOptions) || version_compare(VENDI_CACHE_VERSION, $versionInOptions, '>')) {
            //Either there is no version in options or the version in options is greater and we need to run the upgrade
            self::runInstall();
        }

        wfCache::setupCaching();

        if (defined('MULTISITE') && MULTISITE === true) {
            global $blog_id;
            if ($blog_id == 1 && get_option('wordfenceActivated') != 1) { return; } //Because the plugin is active once installed, even before it's network activated, for site 1 (WordPress team, why?!)
        }

        add_action('publish_future_post', array(__CLASS__, 'publishFuturePost'));
        add_action('mobile_setup', array(__CLASS__, 'jetpackMobileSetup')); //Action called in Jetpack Mobile Theme: modules/minileven/minileven.php

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'admin_init'));
            if ( VENDI_CACHE_SUPPORT_MU && is_multisite()) {
                if (wfUtils::isAdminPageMU()) {
                    add_action('network_admin_menu', array(__CLASS__, 'admin_menus'));
                } //else don't show menu
            } else {
                add_action('admin_menu', array(__CLASS__, 'admin_menus'));
            }
            add_filter('pre_update_option_permalink_structure', array(__CLASS__, 'disablePermalinksFilter'), 10, 2);
            if (preg_match('/^(?:' . cache_settings::CACHE_MODE_ENHANCED . '|' . cache_settings::CACHE_MODE_PHP . ')$/', self::get_vwc_cache_settings()->get_cache_mode())) {
                add_filter('post_row_actions', array(__CLASS__, 'postRowActions'), 0, 2);
                add_filter('page_row_actions', array(__CLASS__, 'pageRowActions'), 0, 2);
                add_action('post_submitbox_start', array(__CLASS__, 'postSubmitboxStart'));
            }
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function jetpackMobileSetup() {
        define('WFDONOTCACHE', true); //Don't cache jetpack mobile theme pages.
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajaxReceiver() {
        if ( ! wfUtils::isAdmin()) {
            die(json_encode(array('errorMsg' => "You appear to have logged out or you are not an admin. Please sign-out and sign-in again.")));
        }
        $func = (isset($_POST['action']) && $_POST['action']) ? $_POST['action'] : $_GET['action'];
        $nonce = (isset($_POST['nonce']) && $_POST['nonce']) ? $_POST['nonce'] : $_GET['nonce'];
        if ( ! wp_verify_nonce($nonce, 'wp-ajax')) {
            die(json_encode(array('errorMsg' => "Your browser sent an invalid security token to Wordfence. Please try reloading this page or signing out and in again.")));
        }
        //func is e.g. wordfence_ticker so need to munge it
        $func = str_replace('wordfence_', '', $func);
        $fq_func = array(__CLASS__, 'ajax_' . $func . '_callback');
        if ( ! is_callable($fq_func))
        {
            die(json_encode(array('errorMsg' => "Could not find AJAX func $func")));
        }
        $returnArr = call_user_func($fq_func);
        if ($returnArr === false) {
            $returnArr = array('errorMsg' => "Wordfence encountered an internal error executing that request.");
        }

        if ( ! is_array($returnArr)) {
            error_log("Function " . wp_kses($func, array()) . " did not return an array and did not generate an error.");
            $returnArr = array();
        }
        if (isset($returnArr['nonce'])) {
            error_log("Wordfence ajax function return an array with 'nonce' already set. This could be a bug.");
        }
        $returnArr['nonce'] = wp_create_nonce('wp-ajax');
        die(json_encode($returnArr));
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function publishFuturePost($id) {
        if (self::get_vwc_cache_settings()->get_do_clear_on_save()) {
            wfCache::scheduleCacheClear();
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function postRowActions($actions, $post) {
        if (wfUtils::isAdmin()) {
            $actions = array_merge($actions, array(
                'wfCachePurge' => '<a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a>'
                ));
        }
        return $actions;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function pageRowActions($actions, $post) {
        if (wfUtils::isAdmin()) {
            $actions = array_merge($actions, array(
                'wfCachePurge' => '<a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a>'
                ));
        }
        return $actions;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function postSubmitboxStart() {
        if (wfUtils::isAdmin()) {
            global $post;
            echo '<div><a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a></div>';
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function disablePermalinksFilter($newVal, $oldVal) {
        if (self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_ENHANCED && $oldVal && ( ! $newVal)) { //Falcon is enabled and admin is disabling permalinks
            wfCache::addHtaccessCode('remove');
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_OFF);
        }
        return $newVal;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_suPHPWAFUpdateChoice_callback() {
        $choice = $_POST['choice'];
        wfConfig::set('suPHPWAFUpdateChoice', '1');
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_removeFromCache_callback() {
        $id = $_POST['id'];
        $link = get_permalink($id);
        if (preg_match('/^https?:\/\/([^\/]+)(.*)$/i', $link, $matches)) {
            $host = $matches[1];
            $URI = $matches[2];
            if ( ! $URI) {
                $URI = '/';
            }
            $sslFile = wfCache::fileFromURI($host, $URI, true); //SSL
            $normalFile = wfCache::fileFromURI($host, $URI, false); //non-SSL
            @unlink($sslFile);
            @unlink($sslFile . '_gzip');
            @unlink($normalFile);
            @unlink($normalFile . '_gzip');
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_saveCacheOptions_callback() {
        $changed = false;
        if ($_POST['allowHTTPSCaching'] != self::get_vwc_cache_settings()->get_do_cache_https_urls()) {
            $changed = true;
        }
        self::get_vwc_cache_settings()->set_do_cache_https_urls($_POST['allowHTTPSCaching'] == 1);
        self::get_vwc_cache_settings()->set_do_append_debug_message($_POST['addCacheComment'] == 1);
        self::get_vwc_cache_settings()->set_do_clear_on_save($_POST['clearCacheSched'] == 1);
        if ($changed && self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_ENHANCED) {
            $err = wfCache::addHtaccessCode('add');
            if ($err) {
                return array('updateErr' => "Wordfence could not edit your .htaccess file. The error was: " . $err, 'code' => wfCache::getHtaccessCode());
            }
        }
        wfCache::scheduleCacheClear();
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_saveCacheConfig_callback() {
        $cacheType = $_POST['cacheType'];
        if ($cacheType == cache_settings::CACHE_MODE_ENHANCED || $cacheType == cache_settings::CACHE_MODE_PHP) {
            $plugins = get_plugins();
            $badPlugins = array();
            foreach ($plugins as $pluginFile => $data) {
                if (is_plugin_active($pluginFile)) {
                    if ($pluginFile == 'w3-total-cache/w3-total-cache.php') {
                        $badPlugins[] = "W3 Total Cache";
                    } else if ($pluginFile == 'quick-cache/quick-cache.php') {
                        $badPlugins[] = "Quick Cache";
                    } else if ($pluginFile == "wp-super-cache/wp-cache.php") {
                        $badPlugins[] = "WP Super Cache";
                    } else if ($pluginFile == "wp-fast-cache/wp-fast-cache.php") {
                        $badPlugins[] = "WP Fast Cache";
                    } else if ($pluginFile == "wp-fastest-cache/wpFastestCache.php") {
                        $badPlugins[] = "WP Fastest Cache";
                    }
                }
            }
            if (count($badPlugins) > 0) {
                return array('errorMsg' => "You can not enable caching in Wordfence with other caching plugins enabled. This may cause conflicts. You need to disable other caching plugins first. Wordfence caching is very fast and does not require other caching plugins to be active. The plugins you have that conflict are: " . implode(', ', $badPlugins) . ". Disable these plugins, then return to this page and enable Wordfence caching.");
            }
            $siteURL = site_url();
            if (preg_match('/^https?:\/\/[^\/]+\/[^\/]+\/[^\/]+\/.+/i', $siteURL)) {
                return array('errorMsg' => "Wordfence caching currently does not support sites that are installed in a subdirectory and have a home page that is more than 2 directory levels deep. e.g. we don't support sites who's home page is http://example.com/levelOne/levelTwo/levelThree");
            }
        }
        if ($cacheType == cache_settings::CACHE_MODE_ENHANCED) {
            if ( ! get_option('permalink_structure', '')) {
                return array('errorMsg' => "You need to enable Permalinks for your site to use Falcon Engine. You can enable Permalinks in WordPress by going to the Settings - Permalinks menu and enabling it there. Permalinks change your site URL structure from something that looks like /p=123 to pretty URLs like /my-new-post-today/ that are generally more search engine friendly.");
            }
        }
        $warnHtaccess = false;
        if ($cacheType == cache_settings::CACHE_MODE_OFF || $cacheType == cache_settings::CACHE_MODE_PHP) {
            $removeError = wfCache::addHtaccessCode('remove');
            if ($removeError || $removeError2) {
                $warnHtaccess = true;
            }
        }
        if ($cacheType == cache_settings::CACHE_MODE_PHP || $cacheType == cache_settings::CACHE_MODE_ENHANCED) {
            $err = wfCache::cacheDirectoryTest();
            if ($err) {
                return array('ok' => 1, 'heading' => "Could not write to cache directory", 'body' => "To enable caching, Wordfence needs to be able to create and write to the /wp-content/wfcache/ directory. We did some tests that indicate this is not possible. You need to manually create the /wp-content/wfcache/ directory and make it writable by Wordfence. The error we encountered was during our tests was: $err");
            }
        }

        //Mainly we clear the cache here so that any footer cache diagnostic comments are rebuilt. We could just leave it intact unless caching is being disabled.
        if ($cacheType != self::get_vwc_cache_settings()->get_cache_mode()) {
            wfCache::scheduleCacheClear();
        }
        $htMsg = "";
        if ($warnHtaccess) {
            $htMsg = " <strong style='color: #F00;'>Warning: We could not remove the caching code from your .htaccess file. you need to remove this manually yourself.</strong> ";
        }
        if ($cacheType == cache_settings::CACHE_MODE_OFF) {
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_OFF);
            return array('ok' => 1, 'heading' => "Caching successfully disabled.", 'body' => "{$htMsg}Caching has been disabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        } else if ($cacheType == cache_settings::CACHE_MODE_PHP) {
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_PHP);
            return array('ok' => 1, 'heading' => "Wordfence Basic Caching Enabled", 'body' => "{$htMsg}Wordfence basic caching has been enabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        } else if ($cacheType == cache_settings::CACHE_MODE_ENHANCED) {
            if ($_POST['noEditHtaccess'] != '1') {
                $err = wfCache::addHtaccessCode('add');
                if ($err) {
                    return array('ok' => 1, 'heading' => "Wordfence could not edit .htaccess", 'body' => "Wordfence could not edit your .htaccess code. The error was: " . $err);
                }
            }
            self::get_vwc_cache_settings()->set_cache_mode(cache_settings::CACHE_MODE_ENHANCED);
            // wfCache::scheduleUpdateBlockedIPs(); //Runs every 5 mins until we change cachetype
            return array('ok' => 1, 'heading' => "Wordfence Falcon Engine Activated!", 'body' => "Wordfence Falcon Engine has been activated on your system. You will see this icon appear on the Wordfence admin pages as long as Falcon is active indicating your site is running in high performance mode:<div class='wfFalconImage'></div><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        }
        return array('errorMsg' => "An error occurred. Probably an unknown cacheType: $cacheType");
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_getCacheStats_callback() {
        $s = wfCache::getCacheStats();
        if ($s['files'] == 0) {
            return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => "The cache is currently empty. It may be disabled or it may have been recently cleared.");
        }
        $body = 'Total files in cache: ' . $s['files'] .
            '<br />Total directories in cache: ' . $s['dirs'] .
            '<br />Total data: ' . $s['data'] . 'KB';
        if ($s['compressedFiles'] > 0) {
            $body .= '<br />Files: ' . $s['uncompressedFiles'] .
                '<br />Data: ' . $s['uncompressedKBytes'] . 'KB' .
                '<br />Compressed files: ' . $s['compressedFiles'] .
                '<br />Compressed data: ' . $s['compressedKBytes'] . 'KB';
        }
        if ($s['largestFile'] > 0) {
            $body .= '<br />Largest file: ' . $s['largestFile'] . 'KB';
        }
        if ($s['oldestFile'] !== false) {
            $body .= '<br />Oldest file in cache created ';
            if (time() - $s['oldestFile'] < 300) {
                $body .= (time() - $s['oldestFile']) . ' seconds ago';
            } else {
                $body .= human_time_diff($s['oldestFile']) . ' ago.';
            }
        }
        if ($s['newestFile'] !== false) {
            $body .= '<br />Newest file in cache created ';
            if (time() - $s['newestFile'] < 300) {
                $body .= (time() - $s['newestFile']) . ' seconds ago';
            } else {
                $body .= human_time_diff($s['newestFile']) . ' ago.';
            }
        }

        return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => $body);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_clearPageCache_callback() {
        $stats = wfCache::clearPageCache();
        if ($stats['error']) {
            $body = "A total of " . $stats['totalErrors'] . " errors occurred while trying to clear your cache. The last error was: " . $stats['error'];
            return array('ok' => 1, 'heading' => 'Error occurred while clearing cache', 'body' => $body);
        }
        $body = "A total of " . $stats['filesDeleted'] . ' files were deleted and ' . $stats['dirsDeleted'] . ' directories were removed. We cleared a total of ' . $stats['totalData'] . 'KB of data in the cache.';
        if ($stats['totalErrors'] > 0) {
            $body .= ' A total of ' . $stats['totalErrors'] . ' errors were encountered. This probably means that we could not remove some of the files or directories in the cache. Please use your CPanel or file manager to remove the rest of the files in the directory: ' . WP_CONTENT_DIR . '/wfcache/';
        }
        return array('ok' => 1, 'heading' => 'Page Cache Cleared', 'body' => $body);
    }
    public static function ajax_updateConfig_callback() {
        $key = $_POST['key'];
        $val = $_POST['val'];
        wfConfig::set($key, $val);
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_checkFalconHtaccess_callback() {
        if (wfUtils::isNginx()) {
            return array('nginx' => 1);
        }
        $file = wfCache::getHtaccessPath();
        if ( ! $file) {
            return array('err' => "We could not find your .htaccess file to modify it.", 'code' => wfCache::getHtaccessCode());
        }
        $fh = @fopen($file, 'r+');
        if ( ! $fh) {
            $err = error_get_last();
            return array('err' => "We found your .htaccess file but could not open it for writing: " . $err['message'], 'code' => wfCache::getHtaccessCode());
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_downloadHtaccess_callback() {
        $url = site_url();
        $url = preg_replace('/^https?:\/\//i', '', $url);
        $url = preg_replace('/[^a-zA-Z0-9\.]+/', '_', $url);
        $url = preg_replace('/^_+/', '', $url);
        $url = preg_replace('/_+$/', '', $url);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="htaccess_Backup_for_' . $url . '.txt"');
        $file = wfCache::getHtaccessPath();
        readfile($file);
        die();
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_addCacheExclusion_callback() {
        $ex = self::get_vwc_cache_settings()->get_cache_exclusions();

        // if($ex){
        //     $ex = unserialize($ex);
        // } else {
        //     $ex = array();
        // }
        $ex[] = array(
            'pt' => $_POST['patternType'],
            'p' => $_POST['pattern'],
            'id' => microtime(true)
            );
        self::get_vwc_cache_settings()->set_cache_exclusions($ex);
        wfCache::scheduleCacheClear();
        if (self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_ENHANCED && preg_match('/^(?:uac|uaeq|cc)$/', $_POST['patternType'])) {
            if (wfCache::addHtaccessCode('add')) { //rewrites htaccess rules
                return array('errorMsg' => "We added the rule you requested but could not modify your .htaccess file. Please delete this rule, check the permissions on your .htaccess file and then try again.");
            }
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_removeCacheExclusion_callback() {
        $id = $_POST['id'];
        $ex = self::get_vwc_cache_settings()->get_cache_exclusions();
        if ( ! $ex || 0 === count($ex))
        {
            return array('ok' => 1);
        }
        $rewriteHtaccess = false;
        for ($i = 0; $i < sizeof($ex); $i++) {
            if ((string) $ex[$i]['id'] == (string) $id) {
                if (self::get_vwc_cache_settings()->get_cache_mode() == cache_settings::CACHE_MODE_ENHANCED && preg_match('/^(?:uac|uaeq|cc)$/', $ex[$i]['pt'])) {
                    $rewriteHtaccess = true;
                }
                array_splice($ex, $i, 1);
                //Dont break in case of dups
            }
        }
        self::get_vwc_cache_settings()->set_cache_exclusions($ex);
        if ($rewriteHtaccess && wfCache::addHtaccessCode('add')) { //rewrites htaccess rules
            return array('errorMsg', "We removed that rule but could not rewrite your .htaccess file. You're going to have to manually remove this rule from your .htaccess file. Please reload this page now.");
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_loadCacheExclusions_callback() {
        $ex = self::get_vwc_cache_settings()->get_cache_exclusions();
        if ( ! $ex || 0 === count($ex))
        {
            return array('ex' => false);
        }
        return array('ex' => $ex);
    }

    public static function admin_init() {
        if ( ! wfUtils::isAdmin()) { return; }
        foreach (array(
            'activate', 'startPasswdAudit',
            'exportSettings', 'importSettings', 'bulkOperation', 'removeExclusion',
            'ticker', 'loadIssues', 'updateIssueStatus', 'updateAllIssues',
            'loadBlockRanges', 'unblockRange', 'whois',
            'loadStaticPanel', 'downloadHtaccess', 'checkFalconHtaccess',
            'updateConfig', 'saveCacheConfig', 'removeFromCache', 'adminEmailChoice', 'suPHPWAFUpdateChoice', 'saveCacheOptions', 'clearPageCache',
            'getCacheStats', 'clearAllBlocked', 'killScan', 'saveCountryBlocking', 'saveScanSchedule',
            'startTourAgain', 'downgradeLicense', 'addTwoFactor', 'twoFacActivate', 'twoFacDel',
            'loadTwoFactor', 'loadAvgSitePerf', 'sendTestEmail', 'addCacheExclusion', 'removeCacheExclusion',
            'loadCacheExclusions',
            'sendDiagnostic', 'whitelistWAFParamKey',
            'hideFileHtaccess', 'wafConfigureAutoPrepend',
            'whitelistBulkDelete', 'whitelistBulkEnable', 'whitelistBulkDisable',
        ) as $func) {
            // if( is_callable( array( __CLASS__, 'ajaxReceiver' )  ) )
            // {
                add_action('wp_ajax_wordfence_' . $func, array(__CLASS__, 'ajaxReceiver'));
            // }
        }

        if (isset($_GET['page']) && preg_match('/^VendiWPCaching/', @$_GET['page'])) {
            wp_enqueue_style('wp-pointer');
            wp_enqueue_script('wp-pointer');
            wp_enqueue_style('wordfence-main-style', wfUtils::getBaseURL() . 'css/main.css', '', VENDI_CACHE_VERSION);
            wp_enqueue_style('wordfence-colorbox-style', wfUtils::getBaseURL() . 'css/colorbox.css', '', VENDI_CACHE_VERSION);
            wp_enqueue_style('wordfence-dttable-style', wfUtils::getBaseURL() . 'css/dt_table.css', '', VENDI_CACHE_VERSION);


            wp_enqueue_script('json2');
            wp_enqueue_script('jquery.wftmpl', wfUtils::getBaseURL() . 'js/jquery.tmpl.min.js', array('jquery'), VENDI_CACHE_VERSION);
            wp_enqueue_script('jquery.wfcolorbox', wfUtils::getBaseURL() . 'js/jquery.colorbox-min.js', array('jquery'), VENDI_CACHE_VERSION);
            wp_enqueue_script('wordfenceAdminjs', wfUtils::getBaseURL() . 'js/admin.js', array('jquery'), VENDI_CACHE_VERSION);
            self::setupAdminVars();
        } else {
            wp_enqueue_style('wp-pointer');
            wp_enqueue_script('wp-pointer');
            self::setupAdminVars();
        }
    }
    private static function setupAdminVars() {
        wp_localize_script('wordfenceAdminjs', 'WordfenceAdminVars', array(
            'ajaxURL' => admin_url('admin-ajax.php'),
            'firstNonce' => wp_create_nonce('wp-ajax'),
            'cacheType' => self::get_vwc_cache_settings()->get_cache_mode(),
            ));
    }
    public static function activation_warning() {
        $activationError = get_option('wf_plugin_act_error', '');
        if (strlen($activationError) > 400) {
            $activationError = substr($activationError, 0, 400) . '...[output truncated]';
        }
        if ($activationError) {
            echo '<div id="wordfenceConfigWarning" class="updated fade"><p><strong>Wordfence generated an error on activation. The output we received during activation was:</strong> ' . wp_kses($activationError, array()) . '</p></div>';
        }
        delete_option('wf_plugin_act_error');
    }
    public static function adminEmailWarning() {
        $url = network_admin_url('admin.php?page=WordfenceSecOpt&wafAction=useMineForAdminEmailAlerts');
        $dismissURL = network_admin_url('admin.php?page=WordfenceSecOpt&wafAction=dismissAdminEmailNotice&nonce=' .
            rawurlencode(wp_create_nonce('wfDismissAdminEmailWarning')));
        echo '<div id="wordfenceAdminEmailWarning" class="fade error"><p><strong>You have not set an administrator email address to receive alerts for Wordfence.</strong> Please <a href="' . self::getMyOptionsURL() . '">click here to go to the Wordfence Options Page</a> and set an email address where you will receive security alerts from this site.</p><p><a class="button button-small" href="#" onclick="wordfenceExt.adminEmailChoice(\'mine\'); return false;"">Use My Email Address</a>
        <a class="button button-small wf-dismiss-link" href="#" onclick="wordfenceExt.adminEmailChoice(\'no\'); return false;">Dismiss</a></p></div>';
    }
    public static function admin_menus() {
        if ( ! wfUtils::isAdmin()) { return; }
        $warningAdded = false;
        if (get_option('wf_plugin_act_error', false)) {
            if (wfUtils::isAdminPageMU()) {
                add_action('network_admin_notices', array(__CLASS__, 'activation_warning'));
            } else {
                add_action('admin_notices', array(__CLASS__, 'activation_warning'));
            }
            $warningAdded = true;
        }

        add_submenu_page( 'options-general.php', 'Vendi Cache', 'Vendi Cache', 'activate_plugins', 'VendiWPCaching', function(){ require VENDI_CACHE_PATH . '/admin/vendi-cache.php'; } );
    }
    public static function _retargetWordfenceSubmenuCallout() {
        echo <<<JQUERY
<script type="text/javascript">
jQuery(document).ready(function($) {
    $('#wfMenuCallout').closest('a').attr('target', '_blank');
});
</script>
JQUERY;

    }
    public static function getMyOptionsURL() {
        return network_admin_url('admin.php?page=WordfenceSecOpt', 'http');
    }
    //PUBLIC API
    public static function doNotCache() { //Call this to prevent Wordfence from caching the current page.
        wfCache::doNotCache();
        return true;
    }

    /**
     * @param string $adminURL
     * @param string $homePath
     * @param bool $relaxedFileOwnership
     * @param bool $output
     * @return bool
     */
    public static function requestFilesystemCredentials($adminURL, $homePath = null, $relaxedFileOwnership = true, $output = true) {
        if ($homePath === null) {
            $homePath = get_home_path();
        }

        global $wp_filesystem;

        ! $output && ob_start();
        if (false === ($credentials = request_filesystem_credentials($adminURL, '', false, $homePath,
                array('version', 'locale'), $relaxedFileOwnership))
        ) {
            ! $output && ob_end_clean();
            return false;
        }

        if ( ! WP_Filesystem($credentials, $homePath, $relaxedFileOwnership)) {
            // Failed to connect, Error and request again
            request_filesystem_credentials($adminURL, '', true, ABSPATH, array('version', 'locale'),
                $relaxedFileOwnership);
            ! $output && ob_end_clean();
            return false;
        }

        if ($wp_filesystem->errors->get_error_code()) {
            ! $output && ob_end_clean();
            return false;
        }
        ! $output && ob_end_clean();
        return true;
    }
}
