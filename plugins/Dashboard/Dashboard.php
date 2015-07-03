<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Dashboard;

use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Category\Subcategory;
use Piwik\Widget\WidgetConfig;

/**
 */
class Dashboard extends \Piwik\Plugin
{
    /**
     * @see Piwik\Plugin::registerEvents
     */
    public function registerEvents()
    {
        return array(
            'AssetManager.getJavaScriptFiles'        => 'getJsFiles',
            'AssetManager.getStylesheetFiles'        => 'getStylesheetFiles',
            'UsersManager.deleteUser'                => 'deleteDashboardLayout',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
            'Widget.addWidgetConfigs'                => 'addWidgetConfigs',
            'Category.addSubcategories'              => 'addSubcategories'
        );
    }

    public function addWidgetConfigs(&$widgets)
    {
        $dashboards = API::getInstance()->getDashboards();

        foreach ($dashboards as $dashboard) {
            $config = new WidgetConfig();
            $config->setIsNotWidgetizable();
            $config->setModule('Dashboard');
            $config->setAction('embeddedIndex');
            $config->setCategoryId('Dashboard_Dashboard');
            $config->setSubcategoryId($dashboard['id']);
            $config->setParameters(array('idDashboard' => $dashboard['id']));
            $widgets[] = $config;
        }
    }

    public function addSubcategories(&$subcategories)
    {
        $dashboards = API::getInstance()->getDashboards();

        $order = 0;
        foreach ($dashboards as $dashboard) {
            $subcategory = new Subcategory();
            $subcategory->setName($dashboard['name']);
            $subcategory->setCategoryId('Dashboard_Dashboard');
            $subcategory->setId($dashboard['id']);
            $subcategory->setOrder($order++);
            $subcategories[] = $subcategory;
        }
    }

    /**
     * Returns the layout in the DB for the given user, or false if the layout has not been set yet.
     * Parameters must be checked BEFORE this function call
     *
     * @param string $login
     * @param int $idDashboard
     *
     * @return bool|string
     */
    public function getLayoutForUser($login, $idDashboard)
    {
        $return = $this->getModel()->getLayoutForUser($login, $idDashboard);

        if (count($return) == 0) {
            return false;
        }

        return $return[0]['layout'];
    }

    private function getModel()
    {
        return new Model();
    }

    public function getDefaultLayout()
    {
        $defaultLayout = $this->getLayoutForUser('', 1);

        if (empty($defaultLayout)) {
            if (Piwik::hasUserSuperUserAccess()) {
                $topWidget = '{"uniqueId":"widgetCoreHomegetDonateForm",'
                    . '"parameters":{"module":"CoreHome","action":"getDonateForm"}},';
            } else {
                $topWidget = '{"uniqueId":"widgetCoreHomegetPromoVideo",'
                    . '"parameters":{"module":"CoreHome","action":"getPromoVideo"}},';
            }

            $defaultLayout = '[
                [
                    {"uniqueId":"widgetVisitsSummarygetEvolutionGraphforceView1viewDataTablegraphEvolution","parameters":{"forceView":"1","viewDataTable":"graphEvolution","module":"VisitsSummary","action":"getEvolutionGraph"}},
                    {"uniqueId":"widgetLivewidget","parameters":{"module":"Live","action":"widget"}},
                    {"uniqueId":"widgetVisitorInterestgetNumberOfVisitsPerVisitDurationviewDataTablecloud","parameters":{"viewDataTable":"cloud","module":"VisitorInterest","action":"getNumberOfVisitsPerVisitDuration"}}
                ],
                [
                    ' . $topWidget . '
                    {"uniqueId":"widgetReferrersgetWebsites","parameters":{"module":"Referrers","action":"getWebsites"}},
                    {"uniqueId":"widgetVisitTimegetVisitInformationPerServerTimeviewDataTablegraphVerticalBar","parameters":{"viewDataTable": "graphVerticalBar","module":"VisitTime","action":"getVisitInformationPerServerTime"}}
                ],
                [
                    {"uniqueId":"widgetUserCountryMapvisitorMap","parameters":{"module":"UserCountryMap","action":"visitorMap"}},
                    {"uniqueId":"widgetDevicesDetectiongetBrowsers","parameters":{"module":"DevicesDetection","action":"getBrowsers"}},
                    {"uniqueId":"widgetReferrersgetSearchEngines","parameters":{"module":"Referrers","action":"getSearchEngines"}},
                    {"uniqueId":"widgetExampleRssWidgetrssPiwik","parameters":{"module":"ExampleRssWidget","action":"rssPiwik"}}
                ]
            ]';
        }

        /**
         * Allows other plugins to modify the default dashboard layout.
         *
         * @param string &$defaultLayout JSON encoded string of the default dashboard layout. Contains an
         *                               array of columns where each column is an array of widgets. Each
         *                               widget is an associative array w/ the following elements:
         *
         *                               * **uniqueId**: The widget's unique ID.
         *                               * **parameters**: The array of query parameters that should be used to get this widget's report.
         */
        Piwik::postEvent("Dashboard.changeDefaultDashboardLayout", array(&$defaultLayout));

        $defaultLayout = $this->removeDisabledPluginFromLayout($defaultLayout);

        return $defaultLayout;
    }

    public function getAllDashboards($login)
    {
        $dashboards = $this->getModel()->getAllDashboardsForUser($login);

        $nameless = 1;
        foreach ($dashboards as &$dashboard) {

            if (empty($dashboard['name'])) {
                $dashboard['name'] = Piwik::translate('Dashboard_DashboardOf', $login);
                if ($nameless > 1) {
                    $dashboard['name'] .= " ($nameless)";
                }

                $nameless++;
            }

            $dashboard['name'] = Common::unsanitizeInputValue($dashboard['name']);

            $layout = '[]';
            if (!empty($dashboard['layout'])) {
                $layout = $dashboard['layout'];
            }

            $dashboard['layout'] = $this->decodeLayout($layout);
        }

        return $dashboards;
    }

    private function isAlreadyDecodedLayout($layout)
    {
        return !is_string($layout);
    }

    public function removeDisabledPluginFromLayout($layout)
    {
        $layoutObject = $this->decodeLayout($layout);

        // if the json decoding works (ie. new Json format)
        // we will only return the widgets that are from enabled plugins

        if (is_array($layoutObject)) {
            $layoutObject = (object)array(
                'config'  => array('layout' => '33-33-33'),
                'columns' => $layoutObject
            );
        }

        if (empty($layoutObject) || empty($layoutObject->columns)) {
            $layoutObject = (object)array(
                'config'  => array('layout' => '33-33-33'),
                'columns' => array()
            );
        }

        $layout = $this->encodeLayout($layoutObject);
        return $layout;
    }

    public function decodeLayout($layout)
    {
        if ($this->isAlreadyDecodedLayout($layout)) {
            return $layout;
        }

        $layout = html_entity_decode($layout);
        $layout = str_replace("\\\"", "\"", $layout);
        $layout = str_replace("\n", "", $layout);

        return json_decode($layout, $assoc = false);
    }

    public function encodeLayout($layout)
    {
        return json_encode($layout);
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/Dashboard/angularjs/common/services/dashboards-model.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/widgetMenu.js";
        $jsFiles[] = "libs/javascript/json2.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboardObject.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboardWidget.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboard.js";
        $jsFiles[] = "plugins/Dashboard/angularjs/dashboard/dashboard.directive.js";
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = "plugins/CoreHome/stylesheets/dataTable.less";
        $stylesheets[] = "plugins/Dashboard/stylesheets/dashboard.less";
        $stylesheets[] = "plugins/Dashboard/stylesheets/widget.less";
    }

    public function deleteDashboardLayout($userLogin)
    {
        $this->getModel()->deleteAllLayoutsForUser($userLogin);
    }

    public function install()
    {
        Model::install();
    }

    public function uninstall()
    {
        Model::uninstall();
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'Dashboard_AddPreviewedWidget';
        $translationKeys[] = 'Dashboard_WidgetPreview';
        $translationKeys[] = 'Dashboard_Maximise';
        $translationKeys[] = 'Dashboard_Minimise';
        $translationKeys[] = 'Dashboard_LoadingWidget';
        $translationKeys[] = 'Dashboard_WidgetNotFound';
        $translationKeys[] = 'Dashboard_DashboardCopied';
        $translationKeys[] = 'General_Close';
        $translationKeys[] = 'General_Refresh';
    }
}
