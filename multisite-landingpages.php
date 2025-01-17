<?php
/*
Plugin Name: Multisite Landingpages
Plugin URI: https://github.com/joerivanveen/multisite-landingpages
Description: Multisite version of ‘Each domain a page’. Assign the slug of a landing page you created to a domain you own for SEO purposes.
Version: 1.2.9
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: multisite-landingpages
Domain Path: /languages/
*/
\defined('ABSPATH') or die();
// @since 1.2.5 the $this->slug is only set when we are on one of the landing pages, so now it signals exactly that
// when $this->slug is nog set, it means we’re on a regular page
// This is plugin nr. 11 by Ruige hond. It identifies as: ruigehond011.
\Define('RUIGEHOND011_VERSION', '1.2.9');
// Register hooks for plugin management, functions are at the bottom of this file.
\register_activation_hook(__FILE__, array(new ruigehond011(), 'activate'));
\register_deactivation_hook(__FILE__, array(new ruigehond011(), 'deactivate'));
\register_uninstall_hook(__FILE__, 'ruigehond011_uninstall');
// Startup the plugin
\add_action('init', array(new ruigehond011(), 'initialize'));

//
class ruigehond011
{
    private $options, $use_canonical, $canonicals, $canonical_prefix, $remove_sitename_from_title = false;
    private $slug, $wpdb, $blog_id, $txt_record, $table_name, $post_types = array();
    private $db_version;
    private $txt_record_mandatory, $manage_cache, $cache_dir;
    private $supported_post_types = ['page', 'post', 'cartflows_step'];

    /**
     * ruigehond011 constructor
     * loads settings that are available also based on current url
     * @since 0.1.0
     */
    public function __construct()
    {
        // define some global vars for this instance
        global $wpdb, $blog_id;
        // use base prefix to make a table shared by all the blogs
        $this->table_name = $wpdb->base_prefix . 'ruigehond011_landingpages';
        $this->wpdb = $wpdb;
        $this->blog_id = isset($blog_id) ? \intval($blog_id) : \null;
        $this->txt_record_mandatory = (\Defined('RUIGEHOND011_TXT_RECORD_MANDATORY')) ?
            \boolval(RUIGEHOND011_TXT_RECORD_MANDATORY) : \true;
        if (\true === ($this->manage_cache = \Defined('RUIGEHOND011_WP_ROCKET_CACHE_DIR'))) {
            if (\is_writable(RUIGEHOND011_WP_ROCKET_CACHE_DIR)) {
                $this->cache_dir = RUIGEHOND011_WP_ROCKET_CACHE_DIR;
                // @since 1.2.4 help WP Rocket clean everything https://harry.plus/blog/wp-rocket-hooks/
                \add_action('after_rocket_clean_domain', array($this, 'removeCacheForEntireSubSite'));
            } else {
                \set_transient('ruigehond011_warning',
                    __('WP Rocket cache directory is not writable or doesn’t exist', 'multisite-landingpages'));
            }
        }
        // get the slug we are using for this request, as far as the plugin is concerned
        // set the slug to the value found in sunrise-functions.php, or null if none was found
        $this->slug = (\Defined('RUIGEHOND011_SLUG')) ? RUIGEHOND011_SLUG : \null;
        // set the options for the current subsite
        $this->options = \get_option('ruigehond011');
        $options_changed = false;
        if (isset($this->options)) {
            $this->use_canonical = (isset($this->options['use_canonical']) and (true === $this->options['use_canonical']));
            if ($this->use_canonical) {
                if (isset($this->options['use_ssl']) and (true === $this->options['use_ssl'])) {
                    $this->canonical_prefix = 'https://';
                } else {
                    $this->canonical_prefix = 'http://';
                }
                if (isset($this->options['use_www']) and (true === $this->options['use_www'])) {
                    $this->canonical_prefix .= 'www.';
                }
            }
            // load the canonicals always (@since 1.2.6)
            $this->canonicals = array();
            if (isset($this->blog_id)) {
                $rows = $this->wpdb->get_results('SELECT domain, post_name FROM ' . $this->table_name .
                    ' WHERE blog_id = ' . $this->blog_id . ' AND approved = 1;');
                foreach ($rows as $index => $row) {
                    $this->canonicals[$row->post_name] = $row->domain;
                }
                $rows = \null;
            }
            $this->remove_sitename_from_title = (isset($this->options['remove_sitename']) and (\true === $this->options['remove_sitename']));
            // get the txt_record value or set it when not available yet
            if (\false === isset($this->options['txt_record'])) { // add the guid to use for txt_record for this subsite
                $this->options['txt_record'] = 'multisite-landingpages=' . \wp_generate_uuid4();
                $options_changed = \true;
            }
            $this->txt_record = $this->options['txt_record'];
            // get the database version number, set it when not yet available
            if (\false === isset($this->options['db_version'])) {
                $this->options['db_version'] = RUIGEHOND011_VERSION;
                $options_changed = \true;
            }
            $this->db_version = $this->options['db_version'];
            // update the options if they were changed during construction
            if (\true === $options_changed) {
                \update_option('ruigehond011', $this->options);
            }
        }
        // https://wordpress.stackexchange.com/a/89965
        //if (isset($this->locale)) add_filter('locale', array($this, 'getLocale'), 1, 1);
    }

    /**
     * initialize the plugin, sets up necessary filters and actions.
     * @since 0.1.0
     */
    public function initialize()
    {
        // for ajax requests that (hopefully) use get_admin_url() you need to set them to the current domain if
        // applicable to avoid cross origin errors
        \add_filter('admin_url', array($this, 'adminUrl'));
        if (is_admin()) {
            // seems excessive but no better stable solution found yet
            // update check only on admin, so make sure to be admin after updating :-)
            $this->updateWhenNecessary();
            \load_plugin_textdomain('multisite-landingpages', false, \dirname(\plugin_basename(__FILE__)) . '/languages/');
            \add_action('admin_init', array($this, 'settings'));
            \add_action('admin_menu', array($this, 'menuitem')); // necessary to have the page accessible to user
            \add_filter('plugin_action_links_' . \plugin_basename(__FILE__), array($this, 'settingslink')); // settings link on plugins page
            if ($this->onSettingsPage()) {
                ruigehond011_display_warning();
                // @since 1.2.7 also cleanup the landingpages table (since wp_delete_site hook does not work)
                $this->wpdb->query('DELETE FROM ' . $this->table_name .
                    ' WHERE blog_id NOT IN (SELECT blog_id FROM ' . $this->wpdb->base_prefix . 'blogs);');
                if (($msg = $this->wpdb->last_error) !== '') \trigger_error($msg);
            }
        } else { // regular visitor
            \add_action('parse_request', array($this, 'get')); // passes WP_Query object
            if (\true === $this->use_canonical) {
                // fix the canonical url for functions that get the url, subject to additions...
                foreach (array(
                             'post_link',
                             'page_link',
                             'post_type_link',
                             'get_canonical_url',
                             'wpseo_opengraph_url', // Yoast
                             'wpseo_canonical', // Yoast
                         ) as $filter) {
                    \add_filter($filter, array($this, 'fixUrl'), 99, 1);
                }
            }
        }
    }

    /**
     * Returns the url for the current slug updated with the specific domain if applicable enabling
     * ajax calls without the dreaded cross origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 0.9.0
     */
    public function adminUrl($url)
    {
        if (isset($this->slug) and isset($this->canonicals[($slug = $this->slug)])) {
            return \str_replace(\get_site_url(), $this->fixUrl($slug), $url);
        }

        return $url;
    }

    /**
     * ‘get’ is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by Wordpress
     * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
     */
    public function get($query)
    {
        if (\false === isset($this->slug)) return $query;
        $slug = $this->slug;
        if (($type = $this->postType($slug))) { // fails when post not found, null is returned which is falsy
            if ($this->remove_sitename_from_title) {
                if (\has_action('wp_head', '_wp_render_title_tag') == 1) {
                    \remove_action('wp_head', '_wp_render_title_tag', 1);
                    \add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
                \add_filter('wpseo_title', array($this, 'get_title'), 1);
            }
            if ('page' === $type) {
                $query->query_vars['pagename'] = $slug;
                $query->query_vars['request'] = $slug;
                $query->query_vars['did_permalink'] = true;
            } elseif (\in_array($type, $this->supported_post_types)) {
                $query->query_vars['page'] = '';
                $query->query_vars['name'] = $slug;
                $query->request = $slug;
                $query->matched_rule = '';
                $query->matched_query = 'name=' . $slug . '$page='; // TODO paging??
                $query->did_permalink = true;
            } // does not work with custom post types (yet)
        }
        //var_dump($query);

        return $query;
    }

    /**
     * substitute for standard wp title rendering to remove the site name
     * @since 0.1.0
     */
    public function render_title_tag()
    {
        echo '<title>' . esc_html(get_the_title()) . '</title>';
    }

    /**
     * substitute title for yoast
     * @since 0.1.0
     */
    public function get_title()
    {
        return get_the_title();
    }

    /**
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     * @since 0.9.0
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
    {
        // -2 = skip over trailing slash, if no slashes are found, $url must be a clean slug, else, extract the last part
        $proposed_slug = (\false === ($index = \strrpos($url, '/', -2))) ? $url : \substr($url, $index + 1);
        $proposed_slug = \trim($proposed_slug, '/');
        if (isset($this->canonicals[$proposed_slug])) {
            $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
        }

        return $url;
    }

    /**
     * @param $slug
     * @return string|null The post-type, or null when not found for this slug
     * @since 0.1.0
     */
    private function postType($slug)
    {
        if (isset($this->post_types[$slug])) return $this->post_types[$slug];
        $sql = 'SELECT post_type FROM ' . $this->wpdb->prefix . 'posts 
        WHERE post_name = \'' . \addslashes($slug) . '\' AND post_status = \'publish\';';
        $type = $this->wpdb->get_var($sql);
        $this->post_types[$slug] = $type;

        return $type;
    }

    /**
     * @return bool true if we are currently on the settings page of this plugin, false otherwise
     */
    private function onSettingsPage()
    {
        return (isset($_GET['page']) && $_GET['page'] === 'multisite-landingpages');
    }

    /**
     * Checks if the required lines for webfonts to work are present in the htaccess
     *
     * @return bool true when the lines are found, false otherwise
     */
    private function htaccessContainsLines()
    {
        $htaccess = \get_home_path() . ".htaccess";
        if (\file_exists($htaccess)) {
            $str = \file_get_contents($htaccess);
            if ($start = \strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
                if (\strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * admin stuff
     */
    public function settings()
    {
        /**
         * register a new setting, call this function for each setting
         * Arguments: (Array)
         * - group, the same as in settings_fields, for security / nonce etc.
         * - the name of the options
         * - the function that will validate the options, valid options are automatically saved by WP
         */
        register_setting('ruigehond011', 'ruigehond011', array($this, 'settings_validate'));
        // new landing page:
        add_settings_section(
            'multisite_landingpages_new',
            __('New landingpage', 'multisite-landingpages'),
            function () {
                echo '<p>';
                echo __('In the DNS settings of your desired domain point the A and / or AAAA records at this WordPress installation.', 'multisite-landingpages');
                echo '<br/>';
                if ($this->txt_record_mandatory) {
                    echo \sprintf(__('Add a TXT record with value: %s', 'multisite-landingpages'),
                        '<strong>' . esc_html($this->txt_record) . '</strong>');
                    echo '<br/>';
                }
                echo __('Fill in the domain name (without protocol or irrelevant subdomains) below to add it.', 'multisite-landingpages');
                echo ' ';
                echo __('The domain must be reachable from this WordPress installation, allow some time for the DNS settings to propagate.', 'multisite-landingpages');
                echo '</p>';
            },
            'ruigehond011'
        );
        // add the necessary field
        add_settings_field(
            'ruigehond011_new',
            'Domain (without www)', // title
            function ($args) {
                echo '<input type="text" name="ruigehond011[domain_new]"/> ';
                echo '<input type="submit" name="submit" value="';
                echo __('Add', 'multisite-landingpages');
                echo '" class="button button-primary"/>';
            },
            'ruigehond011',
            'multisite_landingpages_new',
            [
                'class' => 'ruigehond_row',
            ]
        );
        // landing pages section
        add_settings_section(
            'multisite_landingpages_domains',
            __('Domains and slugs', 'multisite-landingpages'),
            function () {
                echo '<p>';
                echo __('For each domain, you can assign a ‘URL Slug’ from a page or blog post.', 'multisite-landingpages');
                echo ' ';
                echo __('If your landing page is mysite.multisite.com/leads, you would add ‘leads’ in the box below.', 'multisite-landingpages');
                echo ' ';
                echo __('When someone visits your site using the domain, they will see the assigned page or blog post.', 'multisite-landingpages');
                echo '<br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'multisite-landingpages');
                echo ' ';
                echo __('Do not add a domain here if it has already been added as a main website domain.', 'multisite-landingpages');
                echo '</strong></p><input type="hidden" name="ruigehond011[__delete__]"/>';
            },
            'ruigehond011'
        );
        // actual landing pages here
        $rows = $this->wpdb->get_results(
            'SELECT rh.domain, rh.post_name, rh.approved, wp.post_type FROM ' .
            $this->table_name . ' rh LEFT OUTER JOIN ' . $this->wpdb->prefix .
            'posts wp ON (rh.post_name = wp.post_name COLLATE \'utf8mb4_unicode_520_ci\') WHERE blog_id = ' . $this->blog_id .
            ' ORDER BY domain;');
        $txt_record = $this->txt_record;
        foreach ($rows as $index => $row) {
            $domain = $row->domain;
            $slug = $row->post_name;
            add_settings_field(
                'ruigehond011_' . $domain,
                $domain, // title
                function ($args) {
                    $domain = $args['domain'];
                    $slug = $args['slug'];
                    $post_type = $args['post_type'];
                    $approved = \boolval($args['approved']);
                    echo '<input type="text" name="ruigehond011[';
                    echo esc_html($domain);
                    echo ']" value="';
                    echo esc_html($slug);
                    echo '"/> ';
                    // delete button
                    echo '<input type="submit" class="button" value="×" data-domain="';
                    echo esc_html($domain);
                    echo '" onclick="var val = this.getAttribute(\'data-domain\');if (confirm(\'Delete \'+val+\'?\')) {var f = this.form;f[\'ruigehond011[__delete__]\'].value=val;f.submit();}else{return false;}"/> ';
                    if (\true === $approved) {
                        if ($post_type && $slug) {
                            if (!in_array($post_type, $this->supported_post_types)) {
                                echo '<span class="notice-warning notice">';
                                echo esc_html($post_type);
                                echo ' ';
                                echo __('not supported', 'multisite-landingpages');
                                echo '</span>';
                            } elseif ($args['in_canonicals']) {
                                echo '<span class="notice-success notice">';
                                echo esc_html($post_type);
                                echo ' ';
                                if ($args['use_canonical']) {
                                    echo __('loaded in canonicals', 'multisite-landingpages');
                                } else {
                                    echo __('serving', 'multisite-landingpages');
                                }
                                echo '</span>';
                            }
                        } else {
                            echo '<span class="notice-warning notice">';
                            echo __('slug not found', 'multisite-landingpages');
                            echo '</span>';
                        }
                    } else {
                        echo '<span class="notice-warning notice">';
                        echo __('TXT record could not be verified', 'multisite-landingpages');
                        echo '</span>';
                    }
                },
                'ruigehond011',
                'multisite_landingpages_domains',
                [
                    'slug' => $slug,
                    'approved' => $this->checkTxtRecord($domain, $txt_record),
                    'in_canonicals' => isset($this->canonicals[$slug]),
                    'use_canonical' => $this->use_canonical,
                    'post_type' => $row->post_type,
                    'domain' => $domain,
                    'class' => 'ruigehond_row',
                ]
            );
        }
        // register a new section in the page
        add_settings_section(
            'multisite_landingpages_settings', // section id
            __('General options', 'multisite-landingpages'), // title
            function () {
                echo '<p>';
                echo __('Activating the domain name as canonical tells search engines to use the domain as the main url for the pages, preventing duplicate search engine content.', 'multisite-landingpages');
                echo '</p>';
            }, //callback
            'ruigehond011' // page
        );
        // add the checkboxes
        foreach (array(
                     'use_canonical' => __('Set domains as the canonical url', 'multisite-landingpages'),
                     'use_www' => __('Canonicals must include www (incompatible with subdomains)', 'multisite-landingpages'),
                     'use_ssl' => __('Assumes https for all domains', 'multisite-landingpages'),
                     'remove_sitename' => __('Remove the website site name from landing page titles', 'multisite-landingpages'),
                 ) as $setting_name => $short_text) {
            add_settings_field(
                'ruigehond011_' . $setting_name,
                ucfirst(str_replace('_', ' ', $setting_name)), // title
                function ($args) {
                    $setting_name = $args['option_name'];
                    $options = $args['options'];
                    // boolval = bugfix: old versions save ‘true’ as ‘1’
                    $checked = \boolval((isset($options[$setting_name])) ? $options[$setting_name] : false);
                    // make checkbox that transmits 1 or 0, depending on status
                    echo '<label><input type="hidden" name="ruigehond011[';
                    echo esc_html($setting_name);
                    echo ']" value="';
                    echo((true === $checked) ? '1' : '0');
                    echo '"><input type="checkbox"';
                    if (true === $checked) echo ' checked="checked"';
                    echo ' onclick="this.previousSibling.value=1-this.previousSibling.value"/>';
                    echo esc_html($args['label_for']);
                    echo '</label><br/>';
                },
                'ruigehond011',
                'multisite_landingpages_settings',
                [
                    'label_for' => $short_text,
                    'class' => 'ruigehond_row',
                    'options' => $this->options,
                    'option_name' => $setting_name,
                ]
            );
        }
        // display warning about htaccess conditionally
        if ($this->onSettingsPage()) { // show warning only on own options page
            if (($warning = \get_option('ruigehond011_htaccess_warning'))) {
                if ($this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    \delete_option('ruigehond011_htaccess_warning');
                    echo '<div class="notice"><p>';
                    echo __('Warning status cleared.', 'multisite-landingpages');
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>';
                    echo esc_html($warning);
                    echo '</p></div>';
                }
            }
        }
    }

    /**
     * Validates settings, handles saving and deleting of the landingpage domains directly
     * @param $input
     * @return array
     * @since 0.9.0
     */
    public function settings_validate($input)
    {
        $options = (array)\get_option('ruigehond011');
        foreach ($input as $key => $value) {
            switch ($key) {
                // on / off flags (1 vs 0 on form submit, true / false otherwise
                case 'use_canonical':
                case 'use_www':
                case 'use_ssl':
                case 'remove_sitename':
                    $value = ($value === '1' or $value === true); // normalize
                    if (isset($options[$key]) and $options[$key] !== $value) {
                        $this->removeCacheDirIfNecessary('');
                    }
                    $options[$key] = $value;
                    break;
                case 'domain_new':
                    if ($value === '') break; // empty values don’t need to be processed
                    // remove www
                    if (\strpos($value, 'www.') === 0) $value = \substr($value, 4);
                    // test domain utf-8 characters: όνομα.gr
                    if (\function_exists('idn_to_ascii')) {
                        $value = \idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    }
                    // @since 0.9.1: get the txt record, only then the domain is considered valid
                    if (\true === $this->checkTxtRecord($value, $this->txt_record)) {
                        // @since 1.2.5 if the domain exists and ownership is not proven, you cannot take it
                        if (\false === $this->txt_record_mandatory) {
                            $rows = $this->wpdb->get_results('SELECT 1 FROM ' . $this->table_name .
                                ' WHERE domain = \'' . \addslashes($value) . '\'');
                            if (\count($rows) === 1) {
                                \set_transient('ruigehond011_warning',
                                    \sprintf(__('Domain %s already in use', 'multisite-landingpages'), $value));
                                break;
                            }
                        }
                        // if the txt record exists remove any previous entries from the landingpage table
                        $this->wpdb->query('DELETE FROM ' . $this->table_name .
                            ' WHERE domain = \'' . \addslashes($value) . '\'');
                        // and insert this one into the landingpage table
                        $site_id = \get_current_network_id();
                        $this->wpdb->query('INSERT INTO ' . $this->table_name .
                            ' (domain, blog_id, site_id, post_name, txt_record, approved) VALUES(\'' .
                            \addslashes($value) . '\', ' . $this->blog_id . ',' . $site_id . ', \'\', \'' .
                            \addslashes($this->txt_record) . '\', 1)');
                        //var_dump($this->wpdb->last_query);
                        //die(' opa');
                    } else { // message the user...
                        if (\function_exists('idn_to_ascii')) {
                            \set_transient('ruigehond011_warning',
                                __('Please add the required TXT record, note that DNS propagation can take several hours', 'multisite-landingpages'));
                        } else {
                            \set_transient('ruigehond011_warning',
                                __('Please add the required TXT record, note that DNS propagation can take several hours', 'multisite-landingpages') .
                                '<br/><em>' .
                                __('Please note: international domainnames must be put in using ascii notation (punycode)', 'multisite-landingpages') .
                                '</em>');
                        }
                    }
                    break;
                case '__delete__':
                    if ($value === '') break; // empty values don’t need to be processed
                    $this->wpdb->query('DELETE FROM ' . $this->table_name . ' WHERE domain = \'' .
                        \addslashes($value) . '\';');
                    $this->removeCacheDirIfNecessary($value);
                    break;
                default: // this must be a slug change
                    // update the domain - slug combination
                    $value = \sanitize_title($value);
                    $this->wpdb->query('UPDATE ' . $this->table_name . ' SET post_name = \'' .
                        \addslashes($value) . '\' WHERE domain = \'' .
                        \addslashes($key) . '\';');
                    // bust cache only if this is a change
                    if (\false === isset($this->canonicals[$value]) or $this->canonicals[$value] !== $key)
                        $this->removeCacheDirIfNecessary($key);
            }
        }

        return $options;
    }

    /**
     * @param $domain
     * @since 1.2.0 clears WP Rocket cache for the domain
     * @since 1.2.2 accepts empty string to clear everything for the current subsite
     */
    public function removeCacheDirIfNecessary($domain)
    {
        if ($this->manage_cache) {
            if (($domain = \strval($domain)) !== '') {
                if (\is_readable(($path = \trailingslashit($this->cache_dir) . $domain))) {
                    ruigehond011_rmdir($path);
                }
            } else { // @since 1.2.2 clear the cache for the entire subsite
                $this->removeCacheForEntireSubSite();
            }
        }
    }

    public function removeCacheForEntireSubSite()
    {
        // gather all the names / urls this subsite has content under at the moment (nothing less we can do)
        $base_prefix = $this->wpdb->base_prefix;
        $blog_id = $this->blog_id;
        $rows = $this->wpdb->get_results(
            'SELECT domain FROM ' . $this->table_name . ' WHERE blog_id = ' . $blog_id . ';');
        $rows = \array_merge($rows, $this->wpdb->get_results(
            '  SELECT domain FROM ' . $base_prefix . 'blogs WHERE blog_id = ' . $blog_id . ';'));
        if (\true === RUIGEHOND011_DOMAIN_MAPPING_IS_PRESENT) {
            $rows = \array_merge($rows, $this->wpdb->get_results(
                'SELECT domain FROM ' . $base_prefix . 'domain_mapping WHERE blog_id = ' . $blog_id . ';'));
        }
        $domains = array();
        foreach ($rows as $index => $row) {
            // replacement is for multisite in directory mode, UNCONFIRMED TODO need to find a test site for it
            $domains[\str_replace('/', '-', $row->domain)] = \true;
        }
        $rows = \null;
        // remove all the folders by those names
        foreach ($domains as $domain => $ok) {
            if ($domain !== '') $this->removeCacheDirIfNecessary($domain);
        }
        \set_transient('ruigehond011_warning', __('Cleared cache', 'multisite-landingpages'));
    }

    /**
     * @param $domain
     * @param $txt_value
     * @return bool whether the txt_value was found in the txt records for this $domain
     */
    public function checkTxtRecord($domain, $txt_value)
    {
        if (\false === $this->txt_record_mandatory) return \true;
        if (\is_array(($dns_records = \dns_get_record($domain, DNS_TXT)))) {
            // check for the record
            foreach ($dns_records as $index => $record) {
                if (\is_array($record) and isset($record['txt']) and \trim($record['txt']) === $txt_value) {
                    return \true;
                }
            }
        }
        // maybe this is a subdomain, check if a domainname remains after we removed the first thingie, and check recursively
        if (\false !== ($pos = \strpos($domain, '.')) and $domain = \substr($domain, $pos + 1)) {
            if (\false !== \strpos($domain, '.')) {
                return $this->checkTxtRecord($domain, $txt_value);
            }
        }

        return \false;
    }

    public function settingspage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>';
        echo esc_html(get_admin_page_title());
        echo '</h1><form action="options.php" method="post">';
        // output security fields for the registered setting
        settings_fields('ruigehond011');
        // output setting sections and their fields
        do_settings_sections('ruigehond011');
        // output save settings button
        submit_button(__('Save settings', 'multisite-landingpages'));
        echo '</form></div>';
    }

    public function settingslink($links)
    {
        $url = \get_admin_url() . 'options-general.php?page=multisite-landingpages';
        $settings_link = '<a href="' . $url . '">' . __('Settings', 'multisite-landingpages') . '</a>';
        \array_unshift($links, $settings_link);

        return $links;
    }

    public function menuitem()
    {
        add_options_page(
            'Landingpages',
            'Landingpages',
            'manage_options',
            'multisite-landingpages',
            array($this, 'settingspage')
        );
    }

    /**
     * plugin management functions
     */
    public function activate($networkwide)
    {
        if (\false === is_multisite()) wp_die(__('Multisite-landingpages can only be activated on a multisite install', 'multisite-landingpages'));
        // add cross origin for fonts to the htaccess
        if (!$this->htaccessContainsLines()) {
            $htaccess = get_home_path() . ".htaccess";
            $lines = array();
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '<FilesMatch "\.(eot|ttf|otf|woff)$">';
            $lines[] = 'Header set Access-Control-Allow-Origin "*"';
            $lines[] = '</FilesMatch>';
            $lines[] = '</IfModule>';
            if (!insert_with_markers($htaccess, "ruigehond011", $lines)) {
                foreach ($lines as $key => $line) {
                    $lines[$key] = htmlentities($line);
                }
                $warning = '<strong>multisite-landingpages</strong><br/>';
                $warning .= __('In order for webfonts to work on alternative domains you need to have the following lines in your .htaccess', 'multisite-landingpages');
                $warning .= '<br/><em>(';
                $warning .= __('In addition you need to have mod_headers available.', 'multisite-landingpages');
                $warning .= ')</em><br/>&nbsp;<br/>';
                $warning .= '<CODE>' . \implode('<br/>', $lines) . '</CODE>';
                // report the lines to the user
                \update_option('ruigehond011_htaccess_warning', $warning);
            }
        }
        // check if the table already exists, if not create it
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\'') !== $this->table_name) {
            $sql = 'CREATE TABLE ' . $this->table_name . ' (
						domain VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' NOT NULL PRIMARY KEY,
						blog_id BIGINT NOT NULL,
						site_id BIGINT NOT NULL,
						post_name VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						txt_record VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						date_created TIMESTAMP NOT NULL DEFAULT NOW(),
						approved TINYINT NOT NULL DEFAULT 0)
					DEFAULT CHARACTER SET = utf8mb4
					COLLATE = utf8mb4_bin;
					';
            $this->wpdb->query($sql);
        }
    }

    public function updateWhenNecessary()
    {
        if (\version_compare($this->db_version, '0.9.1') < 0) {
            // on busy sites this can be called several times, so suppress the errors
            $this->wpdb->suppress_errors = true;
            // the txt_record added to the landingpage table
            if (\is_null($this->wpdb->get_var("SHOW COLUMNS FROM $this->table_name LIKE 'txt_record'"))) {
                $sql = 'ALTER TABLE ' . $this->table_name .
                    ' ADD COLUMN txt_record VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' AFTER post_name;';
                if ($this->wpdb->query($sql)) {
                    // register current version, but keep incremental updates (for when someone skips a version)
                    $this->options['db_version'] = '0.9.1';
                    \update_option('ruigehond011', $this->options);
                    \set_transient('ruigehond011_warning',
                        \sprintf(__('multisite-landingpages updated database to %s', 'multisite-landingpages'), '0.9.1'));
                }
            }
            $this->wpdb->suppress_errors = false;
        }
    }

    public function deactivate($network_deactivate)
    {
        // deactivate can be done per site or the whole network
        if (\true === $network_deactivate) { // loop through all sites
            if (\false === \wp_is_large_network()) {
                $blogs = get_sites();
                foreach ($blogs as $index => $blog) {
                    \switch_to_blog($blog->blog_id);
                    // remove active plugin (apparently this is not done automatically)
                    $plugins = \get_option('active_plugins');
                    // remove this as an active plugin: multisite-landingpages/multisite-landingpages.php
                    if (\false !== ($key = \array_search('multisite-landingpages/multisite-landingpages.php', $plugins))) {
                        unset($plugins[$key]);
                        \update_option('active_plugins', $plugins);
                    }
                    // remove options
                    \delete_option('ruigehond011');
                    \delete_option('ruigehond011_htaccess_warning'); // should this be present...
                    \restore_current_blog(); // NOTE restore everytime to prevent inconsistent state
                }
            }
        } else {
            // remove options and entries for this blog only
            \delete_option('ruigehond011');
            \delete_option('ruigehond011_htaccess_warning'); // should this be present...
            // remove entries in the landingpage table as well
            // not necessary for network deactivate as the table is dropped on uninstall
            if (isset($this->blog_id)) {
                $this->wpdb->query('DELETE FROM ' . $this->table_name . ' WHERE blog_id = ' . $this->blog_id . ';');
            }
        }
    }

    /**
     * deactivate all instances and then drop the landing pages table
     * @since 1.2.0
     */
    public function network_uninstall()
    {
        // deactivate all instances
        $this->deactivate(\true);
        // uninstall is always a network remove, so you can safely remove the proprietary table here
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\';') === $this->table_name) {
            $this->wpdb->query('DROP TABLE ' . $this->table_name . ';');
        }
    }
}

function ruigehond011_uninstall()
{
    $wphf = new ruigehond011();
    $wphf->network_uninstall();
}

function ruigehond011_display_warning()
{
    /* Check warning, if available display it */
    if (($warning = \get_transient('ruigehond011_warning'))) {
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html($warning);
        echo '</p></div>';
        /* Delete warning, only display this notice once. */
        \delete_transient('ruigehond011_warning');
    }
}

/**
 * @param $dir
 * @since 1.2.0 generic remove directory function
 * @since 1.2.3 refactored using readdir
 */
function ruigehond011_rmdir($dir)
{
    if (\is_dir($dir)) {
        $handle = \opendir($dir);
        while (\false !== ($object = \readdir($handle))) {
            if ($object !== '.' and $object !== '..') {
                $path = $dir . '/' . $object;
                //echo $object . ': ' . \filetype($path) . '<br/>';
                if (\filetype($path) === 'dir') {
                    ruigehond011_rmdir($path);
                } else {
                    \unlink($path);
                }
            }
        }
        \rmdir($dir);
    }
}