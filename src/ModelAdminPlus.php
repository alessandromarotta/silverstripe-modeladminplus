<?php

namespace ilateral\SilverStripe\ModelAdminPlus;

use SilverStripe\ORM\ArrayLib;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\View\Requirements;
use Colymba\BulkManager\BulkManager;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Core\Injector\Injector;
use Colymba\BulkManager\BulkAction\UnlinkHandler;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use Symbiote\GridFieldExtensions\GridFieldConfigurablePaginator;

/**
 * Custom version of model admin that adds extra features
 * (such as submitting search results via a POST, saving the query
 * as a session and automatic Bulk Editing support)
 *
 * @author ilateral
 * @package ModelAdminPlus
 */
abstract class ModelAdminPlus extends ModelAdmin
{
    const EXPORT_FIELDS = "export_fields";

    /**
     * Automatically convert date fields on gridfields
     * to use `Date.Nice`.
     * 
     * @var boolean
     */
    private static $auto_convert_dates = true;

    private static $allowed_actions = [
        "SearchForm"
    ];

    /**
     * List of currently registered ModelAdminSnippets, that is represented as
     * a list of classnames.
     *
     * These snippets are then setup when ModelAdminPlus is initilised and
     * rendered into the ModelAdminPlus content template.
     *
     * @var array
     */
    private static $registered_snippets = [];

    /**
     * Setup 
     */
    public function getSnippets()
    {
        $snippets = ArrayList::create();

        // Setup any model admin plus snippets
        foreach ($this->config()->registered_snippets as $snippet) {
            $snippet = Injector::inst()->create($snippet);
            $snippet->setParent($this);
            $snippets->add($snippet);
        }

        $snippets = $snippets->sort("Order", "DESC");

        $this->extend("updateSnippets", $snippets);

        return $snippets;
    }

    public function init()
    {
        parent::init();

        // Require additional CSS
        Requirements::css("i-lateral/silverstripe-modeladminplus:client/dist/css/admin.css");

        $clear = $this->getRequest()->getVar("clear");

        if (isset($clear) && $clear == 1) {
            $this->clearSearchSession();
            // Remove clear flag
            return $this->redirect(
                $this->Link(
                    $this->sanitiseClassName($this->modelClass)
                )
            );
        }
    }

    /**
     * Get the default export fields for the current model.
     *
     * First this checks if there is an `export_fields` config variable set on
     * the model class, if not, it reverts to the default behaviour.
     *
     * @return array
     */
    public function getExportFields()
    {
        $export_fields = Config::inst()->get(
            $this->modelClass,
            self::EXPORT_FIELDS
        );

        if (isset($export_fields) && is_array($export_fields)) {
            $fields = $export_fields;
        } else {
            $fields = parent::getExportFields();
        }

        $this->extend("updateExportFields", $fields);

        return $fields;
    }

    /**
     * Get the name of the session to be useed by this model admin's search
     * form.
     *
     * @return string
     */
    public function getSearchSessionName()
    {
        $curr = $this->sanitiseClassName(self::class);
        $model = $this->sanitiseClassName($this->modelClass);
        return $curr . "." . $model;
    }

    /**
     * Empty the current search session
     *
     * @return Session
     */
    public function clearSearchSession()
    {
        $session = $this->getRequest()->getSession();
        return $session->clear($this->getSearchSessionName());
    }

    /**
     * Get the current search session
     *
     * @return Session
     */
    public function getSearchSession()
    {
        $session = $this->getRequest()->getSession();
        return $session->get($this->getSearchSessionName());
    }

    /**
     * Set some data to a search session. This needs to be an array of
     * data (like the data submitted by a form).
     *
     * @param array $data An array of data to store in the session
     *
     * @return self
     */
    public function setSearchSession($data)
    {
        $session = $this->getRequest()->getSession();
        return $session->set($this->getSearchSessionName(), $data);
    }

    /**
     * Get the current search results, combined with any saved
     * search results and resturn (as an array).
     *
     * @return array
     */
    public function getSearchData()
    {
        $data = $this->getSearchSession();

        if (!$data || $data && !is_array($data)) {
            $data = [];
        }

        return $data;
    }

    /**
     * Overwrite the default search list to account for the new session based
     * search data.
     *
     * You can override how ModelAdmin returns DataObjects by either overloading this method,
     * or defining an extension to ModelAdmin that implements the `updateList` method
     * (and takes a {@link \SilverStripe\ORM\DataList} as the
     * first argument).
     *
     * @return \SilverStripe\ORM\DataList
     */
    public function getList()
    {
        $context = $this->getSearchContext();
        $params = $this->getSearchData();

        if (is_array($params)) {
            $params = ArrayLib::array_map_recursive('trim', $params);

            // Parse all DateFields to handle user input non ISO 8601 dates
            foreach ($context->getFields() as $field) {
                if ($field instanceof DatetimeField && !empty($params[$field->getName()])) {
                    $params[$field->getName()] = date(
                        'Y-m-d',
                        strtotime($params[$field->getName()])
                    );
                }
            }
        }

        $list = $context->getResults($params);

        $this->extend('updateList', $list);

        return $list;
    }

    /**
     * Gets a list of fields that have been searched
     *
     * @return SilverStripe\ORM\ArrayList
     */
    public function SearchSummary()
    {
        $context = $this->getSearchContext();
        $params = $this->getSearchData();
        $context->setSearchParams($params);

        return $context->getSummary();
    }

    
    /**
     * Add bulk editor to Edit Form
     *
     * @param int|null  $id
     * @param FieldList $fields
     *
     * @return Form A Form object
     */
    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $grid_field = $form
            ->Fields()
            ->fieldByName($this->sanitiseClassName($this->modelClass));

        // Add bulk editing to gridfield
        $manager = new BulkManager();
        $manager->removeBulkAction(UnlinkHandler::class);

        $config = $grid_field->getConfig();

        $config
            ->removeComponentsByType(GridFieldPaginator::class)
            ->addComponent($manager)
            ->addComponent(new GridFieldConfigurablePaginator());

        if ($this->config()->auto_convert_dates) {
            GridFieldDateFinder::create($grid_field)->convertDateFields();
        }

        return $form;
    }

    /**
     * Overwrite default search form
     * 
     * @return Form
     */
    public function SearchForm()
    {
        $form = parent::SearchForm();
        $fields = $form->Fields();
        $data = $this->getSearchData();

        // Change currently scaffolded query fields to use conventional
        // field names
        foreach ($fields as $field) {
            $name = $field->getName();

            if ($name[0] == "q" && $name[1] == "[") {
                $name = substr($name, 2);
            }

            if (substr($name, -1) == "]") {
                $name = substr($name, 0, -1);
            }

            $field->setName($name);
        }

        $form
            ->removeExtraClass('cms-search-form')
            ->setFormMethod('post')
            ->setFormAction(
                $this->Link(
                    Controller::join_links(
                        $this->sanitiseClassName($this->modelClass),
                        $form->getName()
                    )
                )
            )->loadDataFrom($data);

        return $form;
    }

    /**
     * Set the session from the submitted form data (and redirect back)
     *
     * @param array $data Submitted form
     * @param Form  $form The current form
     *
     * @return HTTPResponse
     */
    public function search($data, $form)
    {
        foreach ($data as $key => $value) {
            // Ensure we clear any null values
            // so they don't mess up the list
            if (empty($data[$key])) {
                unset($data[$key]);
            }

            // Ensure we clear any null values
            // so they don't mess up the list
            if (strpos($key, "action_") !== false) {
                unset($data[$key]);
            }
        }

        $this->setSearchSession($data);

        return $this->redirectBack();
    }
}
