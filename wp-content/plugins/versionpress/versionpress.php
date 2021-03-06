<?php
/*
Plugin Name: VersionPress
Plugin URI: http://versionpress.net/
Description: Git-versioning plugin for WordPress
Version: 2.2
Author: VersionPress
Author URI: http://versionpress.net/
License: GPLv2 or later
*/

use VersionPress\Api\VersionPressApi;
use VersionPress\ChangeInfos\PluginChangeInfo;
use VersionPress\ChangeInfos\TranslationChangeInfo;
use VersionPress\ChangeInfos\ThemeChangeInfo;
use VersionPress\ChangeInfos\VersionPressChangeInfo;
use VersionPress\ChangeInfos\WordPressUpdateChangeInfo;
use VersionPress\Database\DbSchemaInfo;
use VersionPress\Database\WpdbMirrorBridge;
use VersionPress\Database\VpidRepository;
use VersionPress\DI\VersionPressServices;
use VersionPress\Git\Reverter;
use VersionPress\Git\RevertStatus;
use VersionPress\Initialization\VersionPressOptions;
use VersionPress\Initialization\WpdbReplacer;
use VersionPress\Storages\Mirror;
use VersionPress\Utils\BugReporter;
use VersionPress\Utils\CompatibilityChecker;
use VersionPress\Utils\CompatibilityResult;
use VersionPress\Utils\FileSystem;
use VersionPress\Utils\IdUtil;
use VersionPress\Utils\UninstallationUtil;
use VersionPress\VersionPress;

defined('ABSPATH') or die("Direct access not allowed");

require_once(__DIR__ . '/bootstrap.php');
require_once( ABSPATH . 'wp-admin/includes/translation-install.php' );

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('vp', 'VersionPress\Cli\VPCommand');
}

if (defined('VP_MAINTENANCE')) {
    vp_disable_maintenance();
}

if (!VersionPress::isActive() && is_file(VERSIONPRESS_PLUGIN_DIR . '/.abort-initialization')) {
    if (UninstallationUtil::uninstallationShouldRemoveGitRepo()) {
        FileSystem::remove(ABSPATH . '.git');
    }

    FileSystem::remove(VERSIONPRESS_MIRRORING_DIR);
    unlink(VERSIONPRESS_PLUGIN_DIR . '/.abort-initialization');
}

if (VersionPress::isActive()) {
    vp_register_hooks();

    register_shutdown_function(function () {
        if (!WpdbReplacer::isReplaced() && !defined('VP_DEACTIVATING')) {
            WpdbReplacer::replaceMethods();
        }
    });

    add_action('wp_loaded', function () {
        if (get_transient('vp_flush_rewrite_rules') && !defined('WP_CLI')) {
            require_once(ABSPATH . 'wp-admin/includes/misc.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            flush_rewrite_rules();
            delete_transient('vp_flush_rewrite_rules');
        }
    });
}

add_filter('automatic_updates_is_vcs_checkout', function () {
    $forceUpdate = UninstallationUtil::uninstallationShouldRemoveGitRepo(); 
    return !$forceUpdate; 
});

function vp_register_hooks() {
    global $wpdb, $versionPressContainer;
    

    $committer = $versionPressContainer->resolve(VersionPressServices::COMMITTER);
    

    $mirror = $versionPressContainer->resolve(VersionPressServices::MIRROR);
    

    $dbSchemaInfo = $versionPressContainer->resolve(VersionPressServices::DB_SCHEMA);
    

    $vpidRepository = $versionPressContainer->resolve(VersionPressServices::VPID_REPOSITORY);

    $wpdbMirrorBridge = $versionPressContainer->resolve(VersionPressServices::WPDB_MIRROR_BRIDGE);

    add_action('save_post', createUpdatePostTermsHook($mirror, $vpidRepository));

    add_filter('update_feedback', function () {
        touch(ABSPATH . 'versionpress.maintenance');
    });
    add_action('_core_updated_successfully', function () use ($committer, $mirror) {
        require(ABSPATH . 'wp-includes/version.php'); 
        

        $changeInfo = new WordPressUpdateChangeInfo($wp_version);
        $committer->forceChangeInfo($changeInfo);

        $mirror->save('option', array('option_name' => 'db_version', 'option_value' => get_option('db_version'))); 

        if (!WpdbReplacer::isReplaced()) {
            WpdbReplacer::replaceMethods();
        }
    });

    add_action('activated_plugin', function ($pluginName) use ($committer) {
        $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'activate'));
    });

    add_action('deactivated_plugin', function ($pluginName) use ($committer) {
        $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'deactivate'));
    });

    add_action('upgrader_process_complete', function ($upgrader, $hook_extra) use ($committer) {
        if ($hook_extra['type'] === 'theme') {
            $themes = (isset($hook_extra['bulk']) && $hook_extra['bulk'] === true) ? $hook_extra['themes'] : array($upgrader->result['destination_name']);
            foreach ($themes as $theme) {
                $themeName = wp_get_theme($theme)->get('Name');
                if ($themeName === $theme && isset($upgrader->skin->api, $upgrader->skin->api->name)) {
                    $themeName =  $upgrader->skin->api->name;
                }

                $action = $hook_extra['action']; 
                $committer->forceChangeInfo(new ThemeChangeInfo($theme, $action, $themeName));
            }
        }

        if (!($hook_extra['type'] === 'plugin' && $hook_extra['action'] === 'update')) return; 

        if (isset($hook_extra['bulk']) && $hook_extra['bulk'] === true) {
            $plugins = $hook_extra['plugins'];
        } else {
            $plugins = array($hook_extra['plugin']);
        }

        foreach ($plugins as $plugin) {
            $committer->forceChangeInfo(new PluginChangeInfo($plugin, 'update'));
        }
    }, 10, 2);

    add_action('added_option', function ($name) use ($wpdb, $mirror) {
        $option = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}options WHERE option_name='$name'", ARRAY_A);
        $mirror->save("option", $option);
    });

    add_filter('upgrader_pre_install', function ($_, $hook_extra) use ($committer) {
        if (!(isset($hook_extra['type']) && $hook_extra['type'] === 'plugin' && $hook_extra['action'] === 'install')) return;

        $pluginsBeforeInstallation = get_plugins();
        $postInstallHook = function ($_, $hook_extra) use ($pluginsBeforeInstallation, $committer, &$postInstallHook) {
            if (!($hook_extra['type'] === 'plugin' && $hook_extra['action'] === 'install')) return;
            wp_cache_delete('plugins', 'plugins');
            $pluginsAfterInstallation = get_plugins();
            $installedPlugin = array_diff_key($pluginsAfterInstallation, $pluginsBeforeInstallation);
            reset($installedPlugin);
            $pluginName = key($installedPlugin);
            $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'install'));
            remove_filter('upgrader_post_install', $postInstallHook);
        };

        add_filter('upgrader_post_install', $postInstallHook, 10, 2);
    }, 10, 2);

    add_filter('upgrader_pre_download', function($reply, $_, $upgrader) use ($committer) {
        if (!isset($upgrader->skin->language_update)) return $reply;
        $languages = get_available_languages();

        $postInstallHook = function ($_, $hook_extra) use ($committer, $languages, &$postInstallHook) {
            if (!isset($hook_extra['language_update_type'])) return;
            $translations = wp_get_available_translations();

            $type = $hook_extra['language_update_type'];
            $languageCode = $hook_extra['language_update']->language;
            $languageName = isset($translations[$languageCode])
                ? $translations[$languageCode]['native_name']
                : 'English (United States)';

            $name = $type === "core" ? null : $hook_extra['language_update']->slug;

            $action = in_array($languageCode, $languages) ? "update" : "install";
            $committer->forceChangeInfo(new TranslationChangeInfo($action, $languageCode, $languageName, $type, $name));
            remove_filter('upgrader_post_install', $postInstallHook);
        };

        add_filter('upgrader_post_install', $postInstallHook, 10, 2);
        return false;
    }, 10, 3);

    add_action('switch_theme', function () use ($committer) {
        if (defined('WP_CLI') && WP_CLI) {
            file_get_contents(admin_url()); 
        } else {
            $committer->disableCommit(); 
        }
    });

    add_action('after_switch_theme', function () use ($committer) {
        $theme = wp_get_theme();
        $stylesheet = $theme->get_stylesheet();
        $themeName = $theme->get('Name');

        $committer->forceChangeInfo(new ThemeChangeInfo($stylesheet, 'switch', $themeName));
    });

    add_action('customize_save_after', function ($customizeManager) use ($committer) {
        

        $stylesheet = $customizeManager->theme()->get_stylesheet();
        $committer->forceChangeInfo(new ThemeChangeInfo($stylesheet, 'customize'));
        register_shutdown_function(function () {
            wp_remote_get(admin_url("admin.php"));
        });
    });

    add_action('untrashed_post_comments', function ($postId) use ($wpdb, $dbSchemaInfo, $wpdbMirrorBridge) {
        $commentsTable = $dbSchemaInfo->getPrefixedTableName("comment");
        $commentStatusSql = "select comment_ID, comment_approved from {$commentsTable} where comment_post_ID = {$postId}";
        $comments = $wpdb->get_results($commentStatusSql, ARRAY_A);

        foreach ($comments as $comment) {
            $wpdbMirrorBridge->update($commentsTable,
                array("comment_approved" => $comment["comment_approved"]),
                array("comment_ID" => $comment["comment_ID"]));
        }
    });

    add_action('delete_post_meta', function ($metaIds) use ($wpdbMirrorBridge, $dbSchemaInfo) {
        $idColumnName = $dbSchemaInfo->getEntityInfo("postmeta")->idColumnName;
        foreach ($metaIds as $metaId) {
            $wpdbMirrorBridge->delete($dbSchemaInfo->getPrefixedTableName("postmeta"), array($idColumnName => $metaId));
        }
    });

    add_action('delete_user_meta', function ($metaIds) use ($wpdbMirrorBridge, $dbSchemaInfo) {
        $idColumnName = $dbSchemaInfo->getEntityInfo("usermeta")->idColumnName;
        foreach ($metaIds as $metaId) {
            $wpdbMirrorBridge->delete($dbSchemaInfo->getPrefixedTableName("usermeta"), array($idColumnName => $metaId));
        }
    });

    add_action('wp_ajax_save-widget', function () use ($committer) {
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['delete_widget']) && $_POST['delete_widget']) {
            $committer->postponeCommit('widgets');
        }
    }, 0); 

    function _vp_get_language_name_by_code($code) {
        $translations = wp_get_available_translations();
        return isset($translations[$code])
            ? $translations[$code]['native_name']
            : 'English (United States)';
    }

    add_action('add_option_WPLANG', function ($option, $value) use ($committer) {
        $defaultLanguage = defined('WPLANG') ? WPLANG : '';
        if ($value === $defaultLanguage) {
            return; 
        }

        $languageName = _vp_get_language_name_by_code($value);
        $committer->forceChangeInfo(new TranslationChangeInfo("activate", $value, $languageName));
    }, 10, 2);

    add_action('update_option_WPLANG', function ($oldValue, $newValue) use ($committer) {
        $languageName = _vp_get_language_name_by_code($newValue);
        $committer->forceChangeInfo(new TranslationChangeInfo("activate", $newValue, $languageName));
    }, 10, 2);

    add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id) use ($committer) {
        $key = 'menu-item-' . $menu_item_db_id;
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'add-menu-item') {
            $committer->postponeCommit($key);
            $committer->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update') {
            $committer->usePostponedChangeInfos($key);
        }
        if (!defined('DOING_AJAX')) {
            global $versionPressContainer;
            

            $mirror = $versionPressContainer->resolve(VersionPressServices::MIRROR);
            $vpidRepository = $versionPressContainer->resolve(VersionPressServices::VPID_REPOSITORY);
            $func = createUpdatePostTermsHook($mirror, $vpidRepository);
            $func($menu_item_db_id);
        }
    }, 10, 2);

    add_action('pre_delete_term', function ($termId, $taxonomy) use ($committer, $vpidRepository) {
        $termVpid = $vpidRepository->getVpidForEntity('term', $termId);
        $term = get_term($termId, $taxonomy);
        $committer->forceChangeInfo(new \VersionPress\ChangeInfos\TermChangeInfo('delete', $termVpid, $term->name, $taxonomy));
    }, 10, 2);

    add_action('set_object_terms', createUpdatePostTermsHook($mirror, $vpidRepository));

    add_filter('plugin_install_action_links', function ($links, $plugin) {
        $compatibility = CompatibilityChecker::testCompatibilityBySlug($plugin['slug']);
        if ($compatibility === CompatibilityResult::COMPATIBLE) {
            $cssClass = 'vp-compatible';
            $compatibilityAdjective = 'Compatible';
        } elseif ($compatibility === CompatibilityResult::INCOMPATIBLE) {
            $cssClass = 'vp-incompatible';
            $compatibilityAdjective = '<a href="http://docs.versionpress.net/en/integrations/plugins" target="_blank" title="This plugin is not compatible with VersionPress. These plugins will not work correctly when used together.">Incompatible</a>';
        } else {
            $cssClass = 'vp-untested';
            $compatibilityAdjective = '<a href="http://docs.versionpress.net/en/integrations/plugins" target="_blank" title="This plugin was not yet tested with VersionPress. Some functionality may not work as intended.">Untested</a>';
        }

        $compatibilityNotice = '<span class="vp-compatibility %s" data-plugin-name="%s"><strong>%s</strong> with VersionPress</span>';
        $links[] = sprintf($compatibilityNotice, $cssClass, $plugin['name'], $compatibilityAdjective);

        return $links;
    }, 10, 2);

    add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file, $plugin_data, $status) {
        if ($status === "dropins") {
            return $plugin_meta;
        }
        $compatibility = CompatibilityChecker::testCompatibilityByPluginFile($plugin_file);
        if ($compatibility === CompatibilityResult::COMPATIBLE) {
            $cssClass = 'vp-compatible';
            $compatibilityAdjective = 'Compatible';
        } elseif ($compatibility === CompatibilityResult::INCOMPATIBLE) {
            $cssClass = 'vp-incompatible';
            $compatibilityAdjective = '<a href="http://docs.versionpress.net/en/integrations/plugins" target="_blank" title="This plugin is not compatible with VersionPress. These plugins will not work correctly when used together.">Incompatible</a>';
        } elseif ($compatibility === CompatibilityResult::UNTESTED) {
            $cssClass = 'vp-untested';
            $compatibilityAdjective = '<a href="http://docs.versionpress.net/en/integrations/plugins" target="_blank" title="This plugin was not yet tested with VersionPress. Some functionality may not work as intended.">Untested</a>';
        } else {
            return $plugin_meta;
        }

        $compatibilityNotice = '<span class="vp-compatibility %s" data-plugin-name="%s"><strong>%s</strong> with VersionPress</span>';
        $plugin_meta[] = sprintf($compatibilityNotice, $cssClass, $plugin_data['Name'], $compatibilityAdjective);

        return $plugin_meta;
    }, 10, 4);

    add_filter('plugin_action_links', function ($actions, $plugin_file) {
        $compatibility = CompatibilityChecker::testCompatibilityByPluginFile($plugin_file);

        if (isset($actions['activate'])) {
            if ($compatibility === CompatibilityResult::UNTESTED) {
                $actions['activate'] = "<span class=\"vp-plugin-list vp-untested\">$actions[activate]</span>";
            } elseif ($compatibility === CompatibilityResult::INCOMPATIBLE) {
                $actions['activate'] = "<span class=\"vp-plugin-list vp-incompatible\">$actions[activate]</span>";
            }
        }
        return $actions;
    }, 10, 2);

    add_action('vp_revert', function () {
        
        
        set_transient('vp_flush_rewrite_rules', 1);
        vp_flush_regenerable_options();
    });

    add_action('pre_delete_term', function ($term, $taxonomy) use ($wpdb, $wpdbMirrorBridge) {
        if (!is_taxonomy_hierarchical($taxonomy)) {
            return;
        }

        $term = get_term($term, $taxonomy);
        if (is_wp_error($term)) {
            return;
        }

        $wpdbMirrorBridge->update($wpdb->term_taxonomy, array('parent' => $term->parent), array('parent' => $term->term_id));
    }, 10, 2);

    add_action('before_delete_post', function ($postId) use ($wpdb) {
            
        $post = get_post($postId);
        if ( !is_wp_error($post) && $post->post_type === 'nav_menu_item' ) {
            \Tracy\Debugger::log('Deleting menu item ' . $post->ID);
            $newParent = get_post_meta($post->ID, '_menu_item_menu_item_parent', true);
            $wpdb->update($wpdb->postmeta,
                array('meta_value' => $newParent),
                array('meta_key' => '_menu_item_menu_item_parent', 'meta_value' => $post->ID)
            );
        }
    });

    $requestDetector = new \VersionPress\Utils\RequestDetector();

    if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'widgets-order') {
        $committer->usePostponedChangeInfos('widgets');
    }

    if ($requestDetector->isThemeDeleteRequest()) {
        $themeIds = $requestDetector->getThemeStylesheets();
        foreach ($themeIds as $themeId) {
            $committer->forceChangeInfo(new ThemeChangeInfo($themeId, 'delete'));
        }
    }

    if ($requestDetector->isPluginDeleteRequest()) {
        $plugins = $requestDetector->getPluginNames();
        foreach ($plugins as $plugin) {
            $committer->forceChangeInfo(new PluginChangeInfo($plugin, 'delete'));
        }
    }

    if ($requestDetector->isCoreLanguageUninstallRequest()) {
        $languageCode = $requestDetector->getLanguageCode();
        $translations = wp_get_available_translations();
        $languageName = isset($translations[$languageCode])
            ? $translations[$languageCode]['native_name']
            : 'English (United States)';

        $committer->forceChangeInfo(new TranslationChangeInfo('uninstall', $languageCode, $languageName, 'core'));
    }

    if (basename($_SERVER['PHP_SELF']) === 'theme-editor.php' && isset($_GET['updated']) && $_GET['updated'] === 'true') {
        $committer->forceChangeInfo(new ThemeChangeInfo($_GET['theme'], 'edit'));
    }

    if (basename($_SERVER['PHP_SELF']) === 'plugin-editor.php' &&
        ((isset($_POST['action']) && $_POST['action'] === 'update') || isset($_GET['liveupdate'])
        )
    ) {
        $committer->disableCommit();
    }

    if (basename($_SERVER['PHP_SELF']) === 'plugin-editor.php' && isset($_GET['a']) && $_GET['a'] === 'te') {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $editedFile = $_GET['file'];
        $editedFilePathParts = preg_split("~[/\\\]~", $editedFile);
        $plugins = array_keys(get_plugins());
        $bestRank = 0;
        $bestMatch = "";

        foreach ($plugins as $plugin) {
            $rank = 0;
            $pluginPathParts = preg_split("~[/\\\]~", $plugin);
            $maxEqualParts = min(count($editedFilePathParts), count($pluginPathParts));

            for ($part = 0; $part < $maxEqualParts; $part++) {
                if ($editedFilePathParts[$part] !== $pluginPathParts[$part]) break;
                $rank += 1;
            }

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestMatch = $plugin;
            }
        }

        $committer->forceChangeInfo(new PluginChangeInfo($bestMatch, 'edit'));
    }

    register_shutdown_function(array($committer, 'commit'));
}

function createUpdatePostTermsHook(Mirror $mirror, VpidRepository $vpidRepository) {

    return function ($postId) use ($mirror, $vpidRepository) {
        

        $post = get_post($postId, ARRAY_A);

        if (!$mirror->shouldBeSaved('post', $post)) {
            return;
        }

        $postType = $post['post_type'];
        $taxonomies = get_object_taxonomies($postType);

        $postVpId = $vpidRepository->getVpidForEntity('post', $postId);

        $postUpdateData = array('vp_id' => $postVpId, 'vp_term_taxonomy' => array());

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if ($terms) {
                $referencedTaxonomies = array_map(function ($term) use ($vpidRepository) {
                    return $vpidRepository->getVpidForEntity('term_taxonomy', $term->term_taxonomy_id);
                }, $terms);

                $postUpdateData['vp_term_taxonomy'] = array_merge($postUpdateData['vp_term_taxonomy'], $referencedTaxonomies);
            }
        }

        if (count($taxonomies) > 0) {
            $mirror->save("post", $postUpdateData);
        }
    };
}

register_activation_hook(__FILE__, 'vp_activate');
register_deactivation_hook(__FILE__, 'vp_deactivate');
add_action('admin_post_cancel_deactivation', 'vp_admin_post_cancel_deactivation');
add_action('admin_post_confirm_deactivation', 'vp_admin_post_confirm_deactivation');
add_action('send_headers', 'vp_send_headers');

if (get_transient('vp_just_activated')) {
    add_filter('gettext', 'vp_gettext_filter_plugin_activated', 10, 3);
}

function vp_gettext_filter_plugin_activated($translation, $text, $domain) {
    if ($text == 'Plugin <strong>activated</strong>.' && get_transient('vp_just_activated')) {
        delete_transient('vp_just_activated');
        return 'VersionPress activated. <strong><a href="' . menu_page_url('versionpress', false) . '" style="text-decoration: underline; font-size: 1.03em;">Continue here</a></strong> to start tracking the site.';
    } else {
        return $translation;
    }
}

function vp_activate() {
    set_transient('vp_just_activated', '1', 10);
}

function vp_deactivate() {
    if (defined('WP_CLI') || !VersionPress::isActive()) {
        vp_admin_post_confirm_deactivation();
    } else {
        wp_redirect(admin_url('admin.php?page=versionpress/admin/deactivate.php'));
        die();
    }
}

function vp_admin_post_cancel_deactivation() {
    wp_redirect(admin_url('plugins.php'));
}

function vp_admin_post_confirm_deactivation() {

    define('VP_DEACTIVATING', true);

    if (WpdbReplacer::isReplaced()) {
        WpdbReplacer::restoreOriginal();
    }

    if (file_exists(VERSIONPRESS_ACTIVATION_FILE)) {
        FileSystem::remove(VERSIONPRESS_ACTIVATION_FILE);
    }

    FileSystem::remove(VERSIONPRESS_MIRRORING_DIR);

    global $versionPressContainer;
    

    $committer = $versionPressContainer->resolve(VersionPressServices::COMMITTER);
    $committer->forceChangeInfo(new VersionPressChangeInfo("deactivate"));

    $wpdbMirrorBridge = $versionPressContainer->resolve(VersionPressServices::WPDB_MIRROR_BRIDGE);
    $wpdbMirrorBridge->disable();

    global $wpdb;

    $table_prefix = $wpdb->prefix;

    $queries[] = "DROP TABLE IF EXISTS `{$table_prefix}vp_id`";

    $vpOptionsReflection = new ReflectionClass('VersionPress\Initialization\VersionPressOptions');
    $usermetaToDelete = array_values($vpOptionsReflection->getConstants());
    $queryRestriction = '"' . join('", "', $usermetaToDelete) . '"';

    $queries[] = "DELETE FROM `{$table_prefix}usermeta` WHERE meta_key IN ({$queryRestriction})";

    foreach ($queries as $query) {
        $wpdb->query($query);
    }

    delete_option('vp_rest_api_plugin_version');
    deactivate_plugins("versionpress/versionpress.php", true);

    if (defined('WP_ADMIN')) {
        wp_redirect(admin_url("plugins.php"));
    }

}

function vp_send_headers() {
    if (isset($_GET['init_versionpress']) && !VersionPress::isActive()) {
        _vp_disable_output_buffering();
    }
}

function _vp_disable_output_buffering() {
    
    ini_set('output_buffering', 'off');
    
    ini_set('zlib.output_compression', false);

    while (@ob_end_flush()) ;

    ini_set('implicit_flush', true);
    ob_implicit_flush(true);

    header("Content-type: text/plain");
    header('Cache-Control: no-cache'); 

    for ($i = 0; $i < 1000; $i++) echo ' ';

    ob_flush();
    flush();
}

add_action('admin_post_vp_send_bug_report', 'vp_send_bug_report');

function vp_send_bug_report() {
    $email = $_POST['email'];
    $description = $_POST['description'];

    $bugReporter = new BugReporter('http://versionpress.net/report-problem');
    $reportedSuccessfully = $bugReporter->reportBug($email, $description);

    $result = $reportedSuccessfully ? "ok" : "err";
    wp_redirect(add_query_arg('bug-report', $result, menu_page_url('versionpress', false)));
}

add_action('admin_notices', 'vp_activation_nag', 4 
);

function vp_activation_nag() {

    if (VersionPress::isActive() ||
        get_current_screen()->id == "toplevel_page_versionpress" ||
        get_current_screen()->id == "versionpress/admin/index" ||
        get_current_screen()->id == "versionpress/admin/deactivate"
    ) {
        return;
    }

    if (get_transient('vp_just_activated')) {
        return;
    }

    echo "<div class='update-nag vp-activation-nag'>VersionPress is installed but not yet tracking this site. <a href='" . menu_page_url('versionpress', false) . "'>Please finish the activation.</a></div>";

}

add_action("after_plugin_row_versionpress/versionpress.php", 'vp_display_activation_notice', 10, 2);

function vp_display_activation_notice($file, $plugin_data) {
    if (VersionPress::isActive()) {
        return;
    }

    $wp_list_table = _get_list_table('WP_Plugins_List_Table');
    $activationUrl = menu_page_url('versionpress', false);
    echo '<tr class="plugin-update-tr vp-plugin-update-tr updated"><td colspan="' . esc_attr( $wp_list_table->get_column_count() ) . '" class="vp-plugin-update plugin-update colspanchange"><div class="update-message vp-update-message">';
    echo 'VersionPress is installed but not yet tracking this site. <a href="' . $activationUrl . '">Please finish the activation.</a>';
    echo '</div></td></tr>';
}

add_filter('wp_insert_post_data', 'vp_generate_post_guid', '99', 2);

function vp_generate_post_guid($data, $postarr) {
    if (!VersionPress::isActive()) {
        return $data;
    }

    if (empty($postarr['ID'])) { 
        $protocol = is_ssl() ? 'https://' : 'http://';
        $data['guid'] = $protocol . IdUtil::newUuid();
    }

    return $data;
}

add_action('admin_menu', 'vp_admin_menu');

function vp_admin_menu() {
    add_menu_page(
        'VersionPress',
        'VersionPress',
        'manage_options',
        'versionpress',
        'versionpress_page',
        null,
        0.001234987
    );

    $directAccessPages = array(
        'deactivate.php',
        'system-info.php',
        'undo.php',
        'index.php'
    );

    global $_registered_pages;
    foreach ($directAccessPages as $directAccessPage) {
        $menu_slug = plugin_basename("versionpress/admin/$directAccessPage");
        $hookname = get_plugin_page_hookname($menu_slug, '');
        $_registered_pages[$hookname] = true;
    }

}

function versionpress_page() {
    require_once(WP_CONTENT_DIR . '/plugins/versionpress/admin/index.php');
}

add_action('admin_action_vp_show_undo_confirm', 'vp_show_undo_confirm');

function vp_show_undo_confirm() {
    if(isAjax()) {
        require_once(WP_CONTENT_DIR . '/plugins/versionpress/admin/undo.php');
    } else {
        wp_redirect(admin_url('admin.php?page=versionpress/admin/undo.php&method=' . $_GET['method'] . '&commit=' . $_GET['commit']));
    }
}

add_action('admin_action_vp_undo', 'vp_undo');

function vp_undo() {
    _vp_revert('undo');
}

add_action('admin_action_vp_rollback', 'vp_rollback');

function vp_rollback() {
    _vp_revert('rollback');
}

function _vp_revert($reverterMethod) {
    global $versionPressContainer;
    

    $reverter = $versionPressContainer->resolve(VersionPressServices::REVERTER);

    $commitHash = $_GET['commit'];
    vp_enable_maintenance();
    $revertStatus = call_user_func(array($reverter, $reverterMethod), $commitHash);
    vp_disable_maintenance();
    $adminPage = menu_page_url('versionpress', false);

    if ($revertStatus !== RevertStatus::OK) {
        wp_redirect(add_query_arg('error', $revertStatus, $adminPage));
    } else {
        wp_redirect($adminPage);
    }
}

if (VersionPress::isActive()) {
    add_action('admin_bar_menu', 'vp_admin_bar_warning');
}

function vp_admin_bar_warning(WP_Admin_Bar $adminBar) {
    if (!current_user_can('activate_plugins')) return;

    $adminBarText = "<span style=\"color:#FF8800;font-weight:bold\">VersionPress EAP running</span>";
    $popoverTitle = "Note";
    $popoverText = "<p style='margin-top: 5px;'>You are running <strong>VersionPress " . VersionPress::getVersion() . "</strong> which is an <strong style='font-size: 1.15em;'>EAP release</strong>. Please understand that EAP releases are early versions of the software and as such might not fully support certain workflows, 3<sup>rd</sup> party plugins, hosts etc.<br /><br /><strong>We recommend that you keep a safe backup of the site at all times</strong></p>";
    $popoverText .= "<p><a href='http://docs.versionpress.net/en/release-notes' target='_blank'>Learn more about VersionPress releases</a></p>";

    $adminBar->add_node(array(
        'id' => 'vp-running',
        'title' => "<a href='#' class='ab-item' id='vp-warning'>$adminBarText</a>
            <script>
            var warning = jQuery('#vp-warning');
            var customPopoverClass = \"versionpress-alpha\"; // used to identify the popover later

            warning.webuiPopover({title:\"$popoverTitle\", content: \"$popoverText\", closeable: true, style: customPopoverClass, width:450});
            </script>",
        'parent' => 'top-secondary'
    ));
}

function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
}

add_action('wp_ajax_hide_vp_welcome_panel', 'vp_ajax_hide_vp_welcome_panel');

function vp_ajax_hide_vp_welcome_panel() {
    update_user_meta(get_current_user_id(), VersionPressOptions::USER_META_SHOW_WELCOME_PANEL, "0");
    die(); 
}

add_action('wp_ajax_vp_show_undo_confirm', 'vp_show_undo_confirm');

add_action('admin_enqueue_scripts', 'vp_enqueue_styles_and_scripts');
add_action('wp_enqueue_scripts', 'vp_enqueue_styles_and_scripts');
function vp_enqueue_styles_and_scripts() {
    if (is_admin_bar_showing()) {
        wp_enqueue_style('versionpress_popover_style', plugins_url('admin/public/css/jquery.webui-popover.min.css', __FILE__));
        wp_enqueue_style('versionpress_popover_custom_style', plugins_url('admin/public/css/popover-custom.css', __FILE__));

        wp_enqueue_script('jquery');
        wp_enqueue_script('versionpress_popover_script', plugins_url('admin/public/js/jquery.webui-popover.min.js', __FILE__), 'jquery');
    }
}

add_action('admin_enqueue_scripts', 'vp_enqueue_admin_styles_and_scripts');
function vp_enqueue_admin_styles_and_scripts() {
    wp_enqueue_style('versionpress_admin_style', plugins_url('admin/public/css/style.css', __FILE__));
    wp_enqueue_style('versionpress_admin_icons', plugins_url('admin/public/icons/style.css', __FILE__));

    wp_enqueue_script('versionpress_admin_script', plugins_url('admin/public/js/vp-admin.js', __FILE__));
}

require("src/Api/BundledWpApi/plugin.php");

header('Access-Control-Allow-Headers: origin, content-type, accept, X-WP-Nonce');
header('Access-Control-Allow-Origin: *');

add_filter('allowed_http_origin', '__return_true');

add_filter('wp_headers', 'vp_send_cors_headers', 11, 1);
function vp_send_cors_headers($headers) {
    $headers['Access-Control-Allow-Origin'] = get_http_origin();
    $headers['Access-Control-Allow-Credentials'] = 'true';

    if ('OPTIONS' == $_SERVER['REQUEST_METHOD']) {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
        }

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            $headers['Access-Control-Allow-Headers'] = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'];
        }
    }
    return $headers;
}

add_action('vp_rest_api_init', 'versionpress_api_init');
function versionpress_api_init() {
    global $versionPressContainer;
    $gitRepository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);
    $reverter = $versionPressContainer->resolve(VersionPressServices::REVERTER);
    $vpConfig = $versionPressContainer->resolve(VersionPressServices::VP_CONFIGURATION);
    $synchronizationProcess = $versionPressContainer->resolve(VersionPressServices::SYNCHRONIZATION_PROCESS);

    $vpApi = new VersionPressApi($gitRepository, $reverter, $vpConfig, $synchronizationProcess);
    $vpApi->register_routes();
}
