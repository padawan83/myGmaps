<?php
/**
 * @brief myGmaps, a plugin for Dotclear 2
 *
 * @package Dotclear
 * @subpackage Plugins
 *
 * @author Philippe aka amalgame and contributors
 *
 * @copyright GPL-2.0 [https://www.gnu.org/licenses/gpl-2.0.html]
 */
declare(strict_types=1);

namespace Dotclear\Plugin\myGmaps;

use dcCore;
use dcNsProcess;
use adminUserPref;
use dcPage;
use Exception;
use form;
use Dotclear\Helper\Html\Html;
use Dotclear\Helper\Network\Http;
use adminPostFilter;

class Manage extends dcNsProcess
{
    /**
     * Initializes the page.
     */
    public static function init(): bool
    {
        if (is_null(dcCore::app()->blog->settings->myGmaps->myGmaps_enabled)) {
            try {
                // Add default settings values if necessary

                $settings = dcCore::app()->blog->settings->myGmaps;

                $settings->put('myGmaps_enabled', false, 'boolean', 'Enable myGmaps plugin', false, true);
                $settings->put('myGmaps_center', '43.0395797336425, 6.126280043989323', 'string', 'Default maps center', false, true);
                $settings->put('myGmaps_zoom', '12', 'integer', 'Default maps zoom level', false, true);
                $settings->put('myGmaps_type', 'roadmap', 'string', 'Default maps type', false, true);
                $settings->put('myGmaps_API_key', 'AIzaSyCUgB8ZVQD88-T4nSgDlgVtH5fm0XcQAi8', 'string', 'Google Maps browser API key', false, true);

                dcCore::app()->blog->triggerBlog();
                Http::redirect(dcCore::app()->admin->getPageURL());
            } catch (Exception $e) {
                dcCore::app()->error->add($e->getMessage());
            }
        }

        self::$init = true;

        return self::$init;
    }

    /**
     * Processes the request(s).
     */
    public static function process(): bool
    {
        if (!self::$init) {
            return false;
        }

        $settings = dcCore::app()->blog->settings->myGmaps;

        dcCore::app()->admin->default_tab = empty($_REQUEST['tab']) ? '' : $_REQUEST['tab'];

        /*
         * Admin page params.
         */

        // Saving configurations
        if (isset($_POST['save'])) {
            $settings->put('relatedEntries_enabled', !empty($_POST['relatedEntries_enabled']));
            $settings->put('relatedEntries_title', Html::escapeHTML($_POST['relatedEntries_title']));
            $settings->put('relatedEntries_beforePost', !empty($_POST['relatedEntries_beforePost']));
            $settings->put('relatedEntries_afterPost', !empty($_POST['relatedEntries_afterPost']));
            $settings->put('relatedEntries_images', !empty($_POST['relatedEntries_images']));

            $opts = [
                'size'     => !empty($_POST['size']) ? $_POST['size'] : 't',
                'html_tag' => !empty($_POST['html_tag']) ? $_POST['html_tag'] : 'div',
                'link'     => !empty($_POST['link']) ? $_POST['link'] : 'entry',
                'exif'     => 0,
                'legend'   => !empty($_POST['legend']) ? $_POST['legend'] : 'none',
                'bubble'   => !empty($_POST['bubble']) ? $_POST['bubble'] : 'image',
                'from'     => !empty($_POST['from']) ? $_POST['from'] : 'full',
                'start'    => !empty($_POST['start']) ? $_POST['start'] : 1,
                'length'   => !empty($_POST['length']) ? $_POST['length'] : 1,
                'class'    => !empty($_POST['class']) ? $_POST['class'] : '',
                'alt'      => !empty($_POST['alt']) ? $_POST['alt'] : 'inherit',
                'img_dim'  => !empty($_POST['img_dim']) ? $_POST['img_dim'] : 0,
            ];

            $settings->put('relatedEntries_images_options', serialize($opts));

            dcCore::app()->blog->triggerBlog();
            Http::redirect(dcCore::app()->admin->getPageURL() . '&upd=1');
        }

        

        return true;
    }

    /**
     * Renders the page.
     */
    public static function render(): void
    {
        if (!self::$init) {
            return;
        }

        $settings = dcCore::app()->blog->settings->myGmaps;

        $myGmaps_center = $settings->myGmaps_center;
        $myGmaps_zoom   = $settings->myGmaps_zoom;
        $myGmaps_type   = $settings->myGmaps_type;

        // Custom map styles

        $public_path = dcCore::app()->blog->public_path;
        $public_url  = dcCore::app()->blog->settings->system->public_url;
        $blog_url    = dcCore::app()->blog->url;

        $map_styles_dir_path = $public_path . '/myGmaps/styles/';
        $map_styles_dir_url  = Http::concatURL(dcCore::app()->blog->url, $public_url . '/myGmaps/styles/');

        if (is_dir($map_styles_dir_path)) {
            $map_styles      = glob($map_styles_dir_path . '*.js');
            $map_styles_list = [];
            foreach ($map_styles as $map_style) {
                $map_style = basename($map_style);
                array_push($map_styles_list, $map_style);
            }
            $map_styles_list     = implode(',', $map_styles_list);
            $map_styles_base_url = $map_styles_dir_url;
        } else {
            $map_styles_list     = '';
            $map_styles_base_url = '';
        }

        // Filters
        dcCore::app()->admin->post_filter = new adminPostFilter();

        // get list params
        $params = dcCore::app()->admin->post_filter->params();

        dcCore::app()->admin->posts      = null;
        dcCore::app()->admin->posts_list = null;

        dcCore::app()->admin->page        = !empty($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        dcCore::app()->admin->nb_per_page = adminUserPref::getUserFilters('pages', 'nb');

        /*
        * Config and list of map elements
        */

        if (isset($_GET['page'])) {
            dcCore::app()->admin->default_tab = 'postslist';
        }

        // Get posts with related posts

        try {
            $params['no_content']            = true;
            $params['sql']                   = 'AND P.post_id IN (SELECT META.post_id FROM ' . dcCore::app()->prefix . 'meta META WHERE META.post_id = P.post_id ' . "AND META.meta_type = 'relatedEntries' ) ";
            dcCore::app()->admin->posts      = dcCore::app()->blog->getPosts($params);
            dcCore::app()->admin->counter    = dcCore::app()->blog->getPosts($params, true);
            dcCore::app()->admin->posts_list = new BackendList(dcCore::app()->admin->posts, dcCore::app()->admin->counter->f(0));
        } catch (Exception $e) {
            dcCore::app()->error->add($e->getMessage());
        }

        dcPage::openModule(
            __('Google Maps'),
            dcPage::jsLoad('js/_posts_list.js') .
            dcPage::jsLoad('https://maps.googleapis.com/maps/api/js?key=' . $settings->myGmaps_API_key . '&amp;libraries=places&amp;callback=Function.prototype') .
            dcPage::jsLoad(DC_ADMIN_URL . '?pf=myGmaps/js/maps.list.js') .
            dcPage::jsLoad(DC_ADMIN_URL . '?pf=myGmaps/js/config.map.js') .
            dcCore::app()->admin->post_filter->js(dcCore::app()->admin->getPageURL() . '#postslist') .
            dcPage::jsPageTabs(dcCore::app()->admin->default_tab) .
            dcPage::jsConfirmClose('config-form') .
            '<link rel="stylesheet" type="text/css" href="index.php?pf=myGmaps/css/admin.css" />'
        );

        echo dcPage::breadcrumb(
            [
                html::escapeHTML(dcCore::app()->blog->name) => '',
                __('Google Maps')                           => dcCore::app()->admin->getPageURL(),
            ]
        ) .
        dcPage::notices();

        // Display messages

    if (isset($_GET['upd'])) {
        $p_msg = '<p class="message">%s</p>';

        $a_msg = [
            __('Configuration has been saved.'),
            __('Elements status has been successfully updated'),
            __('Elements have been successfully marked as selected'),
            __('Elements have been successfully marked as deselected'),
            __('Elements have been successfully deleted'),
            __('Elements category has been successfully changed'),
            __('Elements author has been successfully changed'),
            __('Elements language has been successfully changed'),
        ];

        $k = (int) $_GET['upd'] - 1;

        if (array_key_exists($k, $a_msg)) {
            dcPage::success($a_msg[$k]);
        }
    }

        echo
        '<script>' . "\n" .
        '//<![CDATA[' . "\n";

        echo
            'var neutral_blue_styles = [{"featureType":"water","elementType":"geometry","stylers":[{"color":"#193341"}]},{"featureType":"landscape","elementType":"geometry","stylers":[{"color":"#2c5a71"}]},{"featureType":"road","elementType":"geometry","stylers":[{"color":"#29768a"},{"lightness":-37}]},{"featureType":"poi","elementType":"geometry","stylers":[{"color":"#406d80"}]},{"featureType":"transit","elementType":"geometry","stylers":[{"color":"#406d80"}]},{"elementType":"labels.text.stroke","stylers":[{"visibility":"on"},{"color":"#3e606f"},{"weight":2},{"gamma":0.84}]},{"elementType":"labels.text.fill","stylers":[{"color":"#ffffff"}]},{"featureType":"administrative","elementType":"geometry","stylers":[{"weight":0.6},{"color":"#1a3541"}]},{"elementType":"labels.icon","stylers":[{"visibility":"off"}]},{"featureType":"poi.park","elementType":"geometry","stylers":[{"color":"#2c5a71"}]}];' . "\n" .
            'var neutral_blue = new google.maps.StyledMapType(neutral_blue_styles,{name: "Neutral Blue"});' . "\n";

        if (is_dir($map_styles_dir_path)) {
            $list = explode(',', $map_styles_list);
            foreach ($list as $map_style) {
                $map_style_content = file_get_contents($map_styles_dir_path . '/' . $map_style);
                $var_styles_name   = pathinfo($map_style, PATHINFO_FILENAME);
                $var_name          = preg_replace('/_styles/s', '', $var_styles_name);
                $nice_name         = ucwords(preg_replace('/_/s', ' ', $var_name));
                echo
                'var ' . $var_styles_name . ' = ' . $map_style_content . ';' . "\n" .
                'var ' . $var_name . ' = new google.maps.StyledMapType(' . $var_styles_name . ',{name: "' . $nice_name . '"});' . "\n";
            }
        }

        echo
            '//]]>' . "\n" .
        '</script>';

        if (isset($_GET['upd']) && $_GET['upd'] == 1) {
            dcPage::success(__('Configuration successfully saved'));
        } elseif (isset($_GET['upd']) && $_GET['upd'] == 2) {
            dcPage::success(__('Links have been successfully removed'));
        }

        // Config tab

        echo
        '<div class="multi-part" id="parameters" title="' . __('Parameters') . '">' .
        '<form method="post" action="' . dcCore::app()->admin->getPageURL() . '" id="config-form">' .
        '<div class="fieldset"><h3>' . __('Activation') . '</h3>' .
            '<p><label class="classic" for="myGmaps_enabled">' .
            form::checkbox('myGmaps_enabled', '1', $settings->myGmaps_enabled) .
            __('Enable extension for this blog') . '</label></p>' .
        '</div>' .
        '<div class="fieldset"><h3>' . __('API key') . '</h3>' .
            '<p><label class="maximal" for="myGmaps_API_key">' . __('Google Maps Javascript browser API key:') .
            '<br />' . form::field('myGmaps_API_key', 80, 255, $settings->myGmaps_API_key) .
            '</label></p>';
        if ($settings->myGmaps_API_key == 'AIzaSyCUgB8ZVQD88-T4nSgDlgVtH5fm0XcQAi8') {
            echo '<p class="warn">' . __('You are currently using a <em>shared</em> API key. To avoid map display restrictions on your blog, use your own API key.') . '</p>';
        }

        echo '</div>' .
        '<div class="fieldset"><h3>' . __('Default map options') . '</h3>' .
        '<div class="map_toolbar">' . __('Search:') . '<span class="map_spacer">&nbsp;</span>' .
            '<input size="50" maxlength="255" type="text" id="address" class="qx" /><input id="geocode" type="submit" value="' . __('OK') . '" />' .
        '</div>' .
        '<p class="area" id="map_canvas"></p>' .
        '<p class="form-note info maximal mapinfo" style="width: 100%">' . __('Choose map center by dragging map or searching for a location. Choose zoom level and map type with map controls.') . '</p>' .
            '<p>' .
            '<input type="hidden" name="myGmaps_center" id="myGmaps_center" value="' . $myGmaps_center . '" />' .
            '<input type="hidden" name="myGmaps_zoom" id="myGmaps_zoom" value="' . $myGmaps_zoom . '" />' .
            '<input type="hidden" name="myGmaps_type" id="myGmaps_type" value="' . $myGmaps_type . '" />' .
            '<input type="text" class="hidden" id="map_styles_list" value="' . $map_styles_list . '" />' .
            '<input type="text" class="hidden" id="map_styles_base_url" value="' . $map_styles_base_url . '" />' .
            dcCore::app()->formNonce() .
            '</p></div>' .
            '<p><input type="submit" name="saveconfig" value="' . __('Save configuration') . '" /></p>' .

        '</form>' .
        '</div>' .

        // Related posts list tab

        '<div class="multi-part" id="postslist" title="' . __('Related posts list') . '">';

        dcCore::app()->admin->post_filter->display('admin.plugin.relatedEntries', '<input type="hidden" name="p" value="relatedEntries" /><input type="hidden" name="tab" value="postslist" />');

        // Show posts
        dcCore::app()->admin->posts_list->display(
            dcCore::app()->admin->post_filter->page,
            dcCore::app()->admin->post_filter->nb,
            '<form action="' . dcCore::app()->admin->getPageURL() . '" method="post" id="form-entries">' .

            '%s' .

            '<div class="two-cols">' .
            '<p class="col checkboxes-helpers"></p>' .

            '<p class="col right">' .
            '<input type="submit" class="delete" value="' . __('Remove all links from selected posts') . '" /></p>' .
            '<p>' .
            dcCore::app()->adminurl->getHiddenFormFields('admin.plugin.relatedEntries', dcCore::app()->admin->post_filter->values()) .
            dcCore::app()->formNonce() . '</p>' .
            '</div>' .
            '</form>',
            dcCore::app()->admin->post_filter->show()
        );

        echo
        '</div>';

        dcPage::helpBlock('config');
        dcPage::closeModule();
    }
}