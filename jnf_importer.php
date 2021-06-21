<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use League\Csv\Reader;

class Jnf_Importer extends Module
{
    public function __construct()
    {
        $this->name = 'jnf_importer';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'JnfDev';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Product Importer', [], 'Modules.Jnfimporter.Jnfimporter');
        $this->description = $this->trans('This plugins import a list of products by using a csv file. This plugin is an "admission test" for Interfell.');

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Jnfimporter.Jnfimporter');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function addProduct($name, $ref, $ean13, $wholesale_price, $price, $id_tax_rules_group, $qty, $catDef, $catAll, $id_manufacturer )
    {
        $product = new Product();
        $product->ean13 = $ean13;
        $product->reference = $ref;
        $product->name = $this->createMultiLangField($name);
        $product->id_category_default = (int) $catDef;
        $product->redirect_type = '301';
        $product->price = number_format($price, 6, '.', '');
        $product->wholesale_price = number_format($wholesale_price, 6, '.', '');
        $product->minimal_quantity = 1;
        $product->show_price = 1;
        $product->on_sale = 0;
        $product->online_only = 0;
        $product->meta_description = '';
        $product->link_rewrite = $this->createMultiLangField(Tools::str2url($name));
        $product->id_tax_rules_group = (int) $id_tax_rules_group;
        $product->id_manufacturer = (int) $id_manufacturer;

        // Add the product into database
        $result = $product->add();

        // Stock
        StockAvailable::setQuantity($product->id, null, $qty);

        // Add categories
        $product->addToCategories($catAll);

        return $result ? $product->id : false;
    }

    public function addCategory($name)
    {
        $object = new Category();
        $object->name = $this->createMultiLangField($name);;
        $object->id_parent = Configuration::get('PS_HOME_CATEGORY');
        $object->link_rewrite =  $this->createMultiLangField(Tools::str2url($name));

        return $object->add() ? $object->id : false;
    }

    public function addBrand($name)
    {
        $object = new Manufacturer();
        $object->name = $name;
        $object->link_rewrite = Tools::str2url($name);
        $object->active = true;

        return $object->add() ? $object->id : false;
    }

    public function createMultiLangField($field) 
    {
        $res = array();
        foreach (Language::getIDs(false) as $id_lang) {
            $res[$id_lang] = $field;
        }
        return $res;
    }

    public function getTaxRuleGroupByTaxRate($rate, $id_country, $filter = array())
    {
        $db  = Db::getInstance();
        $sql = 'SELECT `id_tax_rules_group` FROM `'. _DB_PREFIX_ .'tax_rule` WHERE `id_tax` IN (
            SELECT `id_tax` FROM `'. _DB_PREFIX_ .'tax` WHERE `rate` = '. (float) $rate .'
        )';

        $sql .= (is_array($filter) && !empty($filter)) ? ' AND `id_tax_rules_group` IN ('.implode(',', (array) $filter).')' : '';
        $sql .= ($id_country !== false) ? ' AND `id_country` = '. (int) $id_country  : '';

        $taxRuleGroup = $db->getValue($sql);
        
        return !empty($taxRuleGroup) ? $taxRuleGroup : 1;
    }

    public function getCategoryIdByName($name, $id_lang)
    {
        $db  = Db::getInstance();
        $sql = 'SELECT `id_category` FROM `'. _DB_PREFIX_ .'category_lang` WHERE `name` = "'. pSQL($name) .'" AND `id_lang` = ' . $id_lang;

        return $db->getValue($sql);
    }
    

    /** Admin Configuration Page */
   
    public function getContent()
    {
        $output = '';
        $errors = array();

        if (Tools::isSubmit('submit'.$this->name)) {
            
            $file_type     = Tools::strtolower(Tools::substr(strrchr($_FILES['JNF_IMPORTER_FILE']['name'], '.'), 1));
            $temp_location = __DIR__ . '/temp/' . sha1(microtime()) . '.' . $file_type;
            
            if ($file_type !== 'csv') {
                $errors[] = $this->displayError('Wrong file format, the file must be a .csv file!');
            }

            if (!move_uploaded_file($_FILES['JNF_IMPORTER_FILE']['tmp_name'], $temp_location)) {
                $errors[] = $this->displayError('File couldn\'t be uploaded');
            }

            // Process file only
            // if everything is ok
            if (!count($errors)) {
                
                $selected_lang = (int) Tools::getValue('JNF_IMPORTER_LANG');


                $reader = Reader::createFromPath( $temp_location, 'r' );
                $reader->setHeaderOffset(0);
                $records = $reader->getRecords(['name', 'ref', 'ean13', 'wholesale_price', 'price', 'tax_rate', 'qty', 'categories', 'brand']);

                foreach ( $records as $record ) {
                    $taxRulesGroups = array_column(TaxRulesGroup::getTaxRulesGroups(), 'id_tax_rules_group');
                    $taxRulesGroups = array_filter($taxRulesGroups, function($key){
                        return isset($_POST['JNF_IMPORTER_TAX_RULE_GROUPS_'.$key ]);
                    });
    
                    $id_tax_rules_group = $this->getTaxRuleGroupByTaxRate($record['tax_rate'], (int) Tools::getValue('JNF_IMPORTER_COUNTRIES'), $taxRulesGroups);
                    
                    $categoriesIds      = array();
                    $categoriesNames    = explode( ';', $record['categories']);

                    if ( is_array($categoriesNames) && ! empty( $categoriesNames ) ) {
                        foreach ($categoriesNames as $name) {
                            $categoryId = $this->getCategoryIdByName($name, $selected_lang);

                            // Create new category if not exists.
                            if (empty($categoryId)) {
                                $categoryId = $this->addCategory($name);
                            }

                            $categoriesIds[] = (int) $categoryId;
                        }
                    }

                    $defaultCategoryId = isset($categoriesIds[0]) ? (int) $categoriesIds[0] : (int) Configuration::get('PS_HOME_CATEGORY');

                    $brand   = $record['brand'];
                    $brandId = Manufacturer::getIdByName($brand);

                    // If brand is provided but not found it, create it.
                    if (empty($brandId) && !empty($record['brand'])) {
                        $brandId = $this->addBrand($record['brand']);
                    }
    
                    $this->addProduct(
                        $record['name'],
                        $record['ref'],
                        $record['ean13'],
                        $record['wholesale_price'],
                        $record['price'],
                        $id_tax_rules_group,
                        $record['qty'],
                        $defaultCategoryId,
                        $categoriesIds,
                        $brandId
                    );
                }

                @unlink($temp_location);
                $output .= $this->displayConfirmation($this->trans('Importation Finished', [], 'Modules.Jnfimporter.Jnfimporter'));
            }

        }
        $this->context->smarty->assign([
            'admin_products_link' => '#',
        ]);

        $output .= $this->display(__FILE__, 'views/templates/admin/panel.tpl');

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $countries      = Country::getCountries($defaultLang);
        $languages      = Language::getLanguages();
        $taxRulesGroups = TaxRulesGroup::getTaxRulesGroups();

        // Init Fields form array
        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->trans('Importer', [], 'Modules.Jnfimporter.Jnfimporter'),
            ],
            'input' => [
                [
                    'type' => 'select',
                    'label' => 'Language to search categories for',
                    'desc'  => 'Language to match your name\'s categories.',
                    'name' => 'JNF_IMPORTER_LANG',
                    'options' => [
                        'query' => $languages,
                        'id'    => 'id_lang',
                        'name'  => 'name',
                        'default' => [
                            'label' => $this->trans('Shop Default Lang', [], 'Modules.Jnfimporter.Jnfimporter'),
                            'value' => $defaultLang
                        ],
                    ]
                ],
                [
                    'type' => 'select',
                    'label' => 'Country to search rate for',
                    'desc'  => 'Match your IVA rates more precisely.',
                    'name' => 'JNF_IMPORTER_COUNTRIES',
                    'options' => [
                        'query' => $countries,
                        'id'    => 'id_country',
                        'name'  => 'name',
                        'default' => [
                            'label' => $this->trans('Shop Default Country', [], 'Modules.Jnfimporter.Jnfimporter'),
                            'value' => (int) Configuration::get('PS_COUNTRY_DEFAULT')
                        ],
                    ]
                ],
                [
                    'type'   => 'checkbox',
                    'label'  => 'Tax Rules Groups to search rate for',
                    'desc'   => 'Match your IVA rates more precisely.',
                    'name'   => 'JNF_IMPORTER_TAX_RULE_GROUPS',
                    'values' => [
                        'query' => $taxRulesGroups,
                        'id'    => 'id_tax_rules_group',
                        'name'  => 'name',
                    ],
                    'expand' => [
                        'print_total' => count($taxRulesGroups),
                        'default'     => 'show',
                        'show'        => ['text' => $this->trans('show', [], 'Modules.Jnfimporter.Jnfimporter'), 'icon' => 'plus-sign-alt'],
                        'hide'        => ['text' => $this->trans('hide', [], 'Modules.Jnfimporter.Jnfimporter'), 'icon' => 'minus-sign-alt'],
                    ]
                ],
                [
                    'type'  => 'file',
                    'label' => $this->trans('File to Import', [], 'Modules.Jnfimporter.Jnfimporter'),
                    'name'  => 'JNF_IMPORTER_FILE',
                    'display_image' => true,
                ],
            ],
            'submit' => [
                'title' => $this->trans('Save', [], 'Modules.Jnfimporter.Jnfimporter'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->trans('Save', [], 'Modules.Jnfimporter.Jnfimporter'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->trans('Back to list', [], 'Modules.Jnfimporter.Jnfimporter')
            ]
        ];

        // Load current value0
        $helper->fields_value['JNF_IMPORTER_FILE']            = Tools::getValue('JNF_IMPORTER_FILE');
        $helper->fields_value['JNF_IMPORTER_LANG']            = Tools::getValue('JNF_IMPORTER_LANG');
        $helper->fields_value['JNF_IMPORTER_COUNTRIES']       = Tools::getValue('JNF_IMPORTER_COUNTRIES');
        $helper->fields_value['JNF_IMPORTER_TAX_RULE_GROUPS'] = Tools::getValue('JNF_IMPORTER_TAX_RULE_GROUPS');

        return $helper->generateForm($fieldsForm);
    }
}