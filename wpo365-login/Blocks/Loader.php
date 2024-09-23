<?php

namespace Wpo\Blocks;

// Prevent public access to this script
defined('ABSPATH') or die();

use \Wpo\Core\Script_Helpers;
use \Wpo\Services\Options_Service;

if (!class_exists('\Wpo\Blocks\Loader')) {

    class Loader
    {

        public function __construct($app, $edition, $plugins_dir, $plugins_url, $load_front_end = true)
        {

            add_action('enqueue_block_editor_assets', function () use ($app, $edition, $plugins_dir, $plugins_url) {
                $this->enqueue_editor_assets($app, $edition, $plugins_dir, $plugins_url);
            });

            if ($load_front_end) {
                add_action('enqueue_block_assets', function () use ($app, $edition, $plugins_dir, $plugins_url) {
                    $this->enqueue_assets($app, $edition, $plugins_dir, $plugins_url);
                });
            }
        }

        /**
         * Enqueues js / css assets that will only be loaded for the back end.
         * 
         * @since   1.0.0
         * 
         * @return  void
         */
        private function enqueue_editor_assets($app, $edition, $plugins_dir, $plugins_url)
        {
            $editor_block_path = "/Blocks/dist/$app/editor-$edition.js";
            $editor_block_asset_file = include($plugins_dir . "/Blocks/dist/$app/editor-$edition.asset.php");

            // Enqueue the bundled block JS file
            \wp_enqueue_script(
                "wpo365-$app-$edition-editor",
                $plugins_url . $editor_block_path,
                $editor_block_asset_file['dependencies'],
                $editor_block_asset_file['version']
            );

            \wp_add_inline_script("wpo365-$app-$edition-editor", 'window.wpo365 = window.wpo365 || {}; window.wpo365.blocks = ' . json_encode(array(
                'nonce' => \wp_create_nonce('wp_rest'),
                'apiUrl' => \trailingslashit($GLOBALS['WPO_CONFIG']['url_info']['wp_site_url']) . 'wp-json/wpo365/v1/graph',
            )), 'before');

            if ($app == 'aud') {
                $audiences = Options_Service::get_global_list_var('audiences');
                $auth_scenario = Options_Service::get_global_string_var('auth_scenario');
                $keys = array();

                foreach ($audiences as $index => $audience) {
                    $keys[$audience['key']] = $audience['title'];
                }

                \wp_add_inline_script("wpo365-$app-$edition-editor", 'window.wpo365 = window.wpo365 || {}; window.wpo365.blocks = ' . json_encode(array(
                    'nonce' => \wp_create_nonce('wp_rest'),
                    'apiUrl' => \trailingslashit($GLOBALS['WPO_CONFIG']['url_info']['wp_site_url']) . 'wp-json/wpo365/v1/graph',
                )) . '; window.wpo365.aud = ' . \json_encode($keys) . ' ; window.wpo365.scenario = \'' . $auth_scenario . '\'', 'before');
            }
        }

        /**
         * Enqueues js / css assets that will be loaded for both front and back end.
         * 
         * @since   1.0.0
         * 
         * @return  void
         */
        private function enqueue_assets($app, $edition, $plugins_dir, $plugins_url)
        {
            $app_block_path = "/Blocks/dist/$app/app-$edition.js";
            $app_block_asset_file = include($plugins_dir . "/Blocks/dist/$app/app-$edition.asset.php");

            if (is_singular()) {
                $id = get_the_ID();
                $block_type = $edition == 'basic' ? $app . 'Basic' : $app;

                if (has_block('wpo365/' . \strtolower($block_type), $id)) {

                    $react_urls = Script_Helpers::get_react_urls();

                    \wp_enqueue_script('wpo365-unpkg-react', $react_urls['react_url']);
                    \wp_enqueue_script('wpo365-unpkg-react-dom', $react_urls['react_dom_url']);

                    \wp_enqueue_script(
                        "wpo365-$app-$edition-block",
                        $plugins_url . $app_block_path,
                        \array_merge($app_block_asset_file['dependencies'], array('wpo365-unpkg-react', 'wpo365-unpkg-react-dom')),
                        $app_block_asset_file['version'],
                        true
                    ); // Load in footer so the page has rendered and the block with the class can be found

                    \wp_add_inline_script("wpo365-$app-$edition-block", 'window.wpo365 = window.wpo365 || {}; window.wpo365.blocks = ' . json_encode(array(
                        'nonce' => \wp_create_nonce('wp_rest'),
                        'apiUrl' => \trailingslashit($GLOBALS['WPO_CONFIG']['url_info']['wp_site_url']) . 'wp-json/wpo365/v1/graph',
                    )), 'before');
                }
            }
        }
    }
}
