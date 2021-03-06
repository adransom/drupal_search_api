<?php

namespace Drupal\Tests\search_api\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Config\Testing\ConfigSchemaChecker;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\entity_test\Entity\EntityTestMulRevChanged;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;

/**
 * Tests the Views integration of the Search API.
 *
 * @group search_api
 */
class ViewsTest extends SearchApiBrowserTestBase {

  use ExampleContentTrait;
  use StringTranslationTrait;

  /**
   * Modules to enable for this test.
   *
   * @var string[]
   */
  public static $modules = [
    'search_api_test_views',
    'views_ui',
    'language',
    'rest',
  ];

  /**
   * A search index ID.
   *
   * @var string
   */
  protected $indexId = 'database_search_index';

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Add a second language.
    ConfigurableLanguage::createFromLangcode('nl')->save();

    \Drupal::getContainer()
      ->get('search_api.index_task_manager')
      ->addItemsAll(Index::load($this->indexId));
    $this->insertExampleContent();
    $this->indexItems($this->indexId);

    // Do not use a batch for tracking the initial items after creating an
    // index when running the tests via the GUI. Otherwise, it seems Drupal's
    // Batch API gets confused and the test fails.
    if (php_sapi_name() != 'cli') {
      \Drupal::state()->set('search_api_use_tracking_batch', FALSE);
    }
  }

  /**
   * Tests a view with exposed filters.
   */
  public function testView() {
    $this->checkResults([], array_keys($this->entities), 'Unfiltered search');


    $this->checkResults(
      ['search_api_fulltext' => 'foobar'],
      [3],
      'Search for a single word'
    );
    $this->checkResults(
      ['search_api_fulltext' => 'foo test'],
      [1, 2, 4],
      'Search for multiple words'
    );
    $query = [
      'search_api_fulltext' => 'foo test',
      'search_api_fulltext_op' => 'or',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'OR search for multiple words');
    $query = [
      'search_api_fulltext' => 'foobar',
      'search_api_fulltext_op' => 'not',
    ];
    $this->checkResults($query, [1, 2, 4, 5], 'Negated search');
    $query = [
      'search_api_fulltext' => 'foo test',
      'search_api_fulltext_op' => 'not',
    ];
    $this->checkResults($query, [], 'Negated search for multiple words');
    $query = [
      'search_api_fulltext' => 'fo',
    ];
    $label = 'Search for short word';
    $this->checkResults($query, [], $label);
    $this->assertSession()->pageTextContains('You must include at least one positive keyword with 3 characters or more');
    $query = [
      'search_api_fulltext' => 'foo to test',
    ];
    $label = 'Fulltext search including short word';
    $this->checkResults($query, [1, 2, 4], $label);
    $this->assertSession()->pageTextNotContains('You must include at least one positive keyword with 3 characters or more');

    $this->checkResults(['id[value]' => 2], [2], 'Search with ID filter');

    $query = [
      'id[min]' => 2,
      'id[max]' => 4,
      'id_op' => 'between',
    ];
    $this->checkResults($query, [2, 3, 4], 'Search with ID "in between" filter');

    $query = [
      'id[min]' => 2,
      'id[max]' => 4,
      'id_op' => 'not between',
    ];
    $this->checkResults($query, [1, 5], 'Search with ID "not in between" filter');

    $query = [
      'id[value]' => 2,
      'id_op' => '>',
    ];
    $this->checkResults($query, [3, 4, 5], 'Search with ID "greater than" filter');
    $query = [
      'id[value]' => 2,
      'id_op' => '!=',
    ];
    $this->checkResults($query, [1, 3, 4, 5], 'Search with ID "not equal" filter');
    $query = [
      'id_op' => 'empty',
    ];
    $this->checkResults($query, [], 'Search with ID "empty" filter');
    $query = [
      'id_op' => 'not empty',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with ID "not empty" filter');

    $yesterday = strtotime('-1DAY');
    $query = [
      'created[value]' => date('Y-m-d', $yesterday),
      'created_op' => '>',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with "Created after" filter');
    $query = [
      'created[value]' => date('Y-m-d', $yesterday),
      'created_op' => '<',
    ];
    $this->checkResults($query, [], 'Search with "Created before" filter');
    $query = [
      'created_op' => 'empty',
    ];
    $this->checkResults($query, [], 'Search with "empty creation date" filter');
    $query = [
      'created_op' => 'not empty',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with "not empty creation date" filter');

    $this->checkResults(['keywords[value]' => 'apple'], [2, 4], 'Search with Keywords filter');
    $query = [
      'keywords[min]' => 'aardvark',
      'keywords[max]' => 'calypso',
      'keywords_op' => 'between',
    ];
    $this->checkResults($query, [2, 4, 5], 'Search with Keywords "in between" filter');

    // For the keywords filters with comparison operators, exclude entity 1
    // since that contains all the uppercase and special characters weirdness.
    $query = [
      'id[value]' => 1,
      'id_op' => '!=',
      'keywords[value]' => 'melon',
      'keywords_op' => '>=',
    ];
    $this->checkResults($query, [2, 4, 5], 'Search with Keywords "greater than or equal" filter');
    $query = [
      'id[value]' => 1,
      'id_op' => '!=',
      'keywords[value]' => 'banana',
      'keywords_op' => '<',
    ];
    $this->checkResults($query, [2, 4], 'Search with Keywords "less than" filter');
    $query = [
      'keywords[value]' => 'orange',
      'keywords_op' => '!=',
    ];
    $this->checkResults($query, [3, 4], 'Search with Keywords "not equal" filter');
    $query = [
      'keywords_op' => 'empty',
    ];
    $label = 'Search with Keywords "empty" filter';
    $this->checkResults($query, [3], $label, 'all/all/all');
    $query = [
      'keywords_op' => 'not empty',
    ];
    $this->checkResults($query, [1, 2, 4, 5], 'Search with Keywords "not empty" filter');

    $query = [
      'language' => ['***LANGUAGE_site_default***'],
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with "Page content language" filter');
    $query = [
      'language' => ['en'],
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with "English" language filter');
    $query = [
      'language' => [Language::LANGCODE_NOT_SPECIFIED],
    ];
    $this->checkResults($query, [], 'Search with "Not specified" language filter');
    $query = [
      'language' => [
        '***LANGUAGE_language_interface***',
        'zxx',
      ],
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with multiple languages filter');

    $query = [
      'search_api_fulltext' => 'foo to test',
      'id[value]' => 2,
      'id_op' => '>',
      'keywords_op' => 'not empty',
    ];
    $this->checkResults($query, [4], 'Search with multiple filters');

    // Test contextual filters. Configured contextual filters are:
    // 1: datasource
    // 2: type (not = true)
    // 3: keywords (break_phrase = true)
    $this->checkResults([], [4, 5], 'Search with arguments', 'entity:entity_test_mulrev_changed/item/grape');

    // "Type" doesn't have "break_phrase" enabled, so the second argument won't
    // have any effect.
    $this->checkResults([], [2, 4, 5], 'Search with arguments', 'all/item+article/strawberry+apple');

    $this->checkResults([], [], 'Search with unknown datasource argument', 'entity:foobar/all/all');

    $query = [
      'id[value]' => 1,
      'id_op' => '!=',
      'keywords[value]' => 'melon',
      'keywords_op' => '>=',
    ];
    $this->checkResults($query, [2, 5], 'Search with arguments and filters', 'entity:entity_test_mulrev_changed/all/orange');

    // Make sure the datasource filter works correctly with multiple selections.
    $index = Index::load($this->indexId);
    $index->addDatasource($index->createPlugin('datasource', 'entity:user'));
    $index->save();

    $query = [
      'datasource' => ['entity:user', 'entity:entity_test_mulrev_changed'],
      'datasource_op' => 'or',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search with multiple datasource filters (OR)');

    $query = [
      'datasource' => ['entity:user', 'entity:entity_test_mulrev_changed'],
      'datasource_op' => 'and',
    ];
    $this->checkResults($query, [], 'Search with multiple datasource filters (AND)');

    $query = [
      'datasource' => ['entity:user'],
      'datasource_op' => 'not',
    ];
    $this->checkResults($query, [1, 2, 3, 4, 5], 'Search for non-user results');

    $query = [
      'datasource' => ['entity:entity_test_mulrev_changed'],
      'datasource_op' => 'not',
    ];
    $this->checkResults($query, [], 'Search for non-test entity results');

    $query = [
      'datasource' => ['entity:user', 'entity:entity_test_mulrev_changed'],
      'datasource_op' => 'not',
    ];
    $this->checkResults($query, [], 'Search for results of no available datasource');

    // Make sure there was a display plugin created for this view.
    $displays = \Drupal::getContainer()->get('plugin.manager.search_api.display')
      ->getInstances();

    if ($displays === []) {
      throw new SearchApiException("No displays are loaded, tests will fail.");
    }

    $display_id = 'views_page:search_api_test_view__page_1';
    $this->assertTrue(array_key_exists($display_id, $displays), 'A display plugin was created for the test view page display.');
    $this->assertTrue(array_key_exists('views_block:search_api_test_view__block_1', $displays), 'A display plugin was created for the test view block display.');
    $this->assertTrue(array_key_exists('views_rest:search_api_test_view__rest_export_1', $displays), 'A display plugin was created for the test view block display.');
    $view_url = Url::fromUserInput('/search-api-test')->toString();
    $this->assertEquals($view_url, $displays[$display_id]->getUrl()->toString(), 'Display returns the correct path.');
    $this->assertEquals('database_search_index', $displays[$display_id]->getIndex()->id(), 'Display returns the correct search index.');

    $admin_user = $this->drupalCreateUser([
      'administer search_api',
      'access administration pages',
      'administer views',
    ]);
    $this->drupalLogin($admin_user);

    // Delete the page display for the view.
    $this->drupalGet('admin/structure/views/view/search_api_test_view');
    $this->submitForm([], $this->t('Delete Page'));
    $this->submitForm([], $this->t('Save'));

    drupal_flush_all_caches();

    $displays = \Drupal::getContainer()->get('plugin.manager.search_api.display')
      ->getInstances();
    $this->assertFalse(array_key_exists('views_page:search_api_test_view__page_1', $displays), 'A display plugin was created for the test view page display.');
    $this->assertTrue(array_key_exists('views_block:search_api_test_view__block_1', $displays), 'A display plugin was created for the test view block display.');
    $this->assertTrue(array_key_exists('views_rest:search_api_test_view__rest_export_1', $displays), 'A display plugin was created for the test view block display.');
  }

  /**
   * Checks the Views results for a certain set of parameters.
   *
   * @param array $query
   *   The GET parameters to set for the view.
   * @param int[]|null $expected_results
   *   (optional) The IDs of the expected results; or NULL to skip checking the
   *   results.
   * @param string $label
   *   (optional) A label for this search, to include in assert messages.
   * @param string $arguments
   *   (optional) A string to append to the search path.
   */
  protected function checkResults(array $query, array $expected_results = NULL, $label = 'Search', $arguments = '') {
    $this->drupalGet('search-api-test/' . $arguments, ['query' => $query]);

    if (isset($expected_results)) {
      $count = count($expected_results);
      if ($count) {
        $this->assertSession()->pageTextContains("Displaying $count search results");
      }
      else {
        $this->assertSession()->pageTextNotContains('search results');
      }

      $expected_results = array_combine($expected_results, $expected_results);
      $actual_results = [];
      foreach ($this->entities as $id => $entity) {
        $entity_label = Html::escape($entity->label());
        if (strpos($this->getSession()->getPage()->getContent(), ">$entity_label<") !== FALSE) {
          $actual_results[$id] = $id;
        }
      }
      $this->assertEquals($expected_results, $actual_results, "$label returned correct results.");
    }
  }

  /**
   * Test Views admin UI and field handlers.
   */
  public function testViewsAdmin() {
    // Add some Dutch nodes.
    foreach ([1, 2, 3, 4, 5] as $id) {
      $entity = EntityTestMulRevChanged::load($id);
      $entity = $entity->addTranslation('nl', [
        'body' => "dutch node $id",
        'category' => "dutch category $id",
        'keywords' => ["dutch $id A", "dutch $id B"],
      ]);
      $entity->save();
    }
    $this->entities = EntityTestMulRevChanged::loadMultiple();
    $this->indexItems($this->indexId);

    // For viewing the user name and roles of the user associated with test
    // entities, the logged-in user needs to have the permission to administer
    // both users and permissions.
    $permissions = [
      'administer search_api',
      'access administration pages',
      'administer views',
      'administer users',
      'administer permissions',
    ];
    $this->drupalLogin($this->drupalCreateUser($permissions));

    $this->drupalGet('admin/structure/views/view/search_api_test_view');
    $this->assertSession()->statusCodeEquals(200);

    // Set the user IDs associated with our test entities.
    $users[] = $this->createUser();
    $users[] = $this->createUser();
    $users[] = $this->createUser();
    $this->entities[1]->setOwnerId($users[0]->id())->save();
    $this->entities[2]->setOwnerId($users[0]->id())->save();
    $this->entities[3]->setOwnerId($users[1]->id())->save();
    $this->entities[4]->setOwnerId($users[1]->id())->save();
    $this->entities[5]->setOwnerId($users[2]->id())->save();

    // Switch to "Table" format.
    $this->clickLink($this->t('Unformatted list'));
    $this->assertSession()->statusCodeEquals(200);
    $edit = [
      'style[type]' => 'table',
    ];
    $this->submitForm($edit, $this->t('Apply'));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], $this->t('Apply'));
    $this->assertSession()->statusCodeEquals(200);

    // Add the "User ID" relationship.
    $this->clickLink($this->t('Add relationships'));
    $edit = [
      'name[search_api_datasource_database_search_index_entity_entity_test_mulrev_changed.user_id]' => 'search_api_datasource_database_search_index_entity_entity_test_mulrev_changed.user_id',
    ];
    $this->submitForm($edit, $this->t('Add and configure relationships'));
    $this->submitForm([], $this->t('Apply'));

    // Add new fields. First check that the listing seems correct.
    $this->clickLink($this->t('Add fields'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->t('Test entity - revisions and data table datasource'));
    $this->assertSession()->pageTextContains($this->t('Authored on'));
    $this->assertSession()->pageTextContains($this->t('Body (indexed field)'));
    $this->assertSession()->pageTextContains($this->t('Index Test index'));
    $this->assertSession()->pageTextContains($this->t('Item ID'));
    $this->assertSession()->pageTextContains($this->t('Excerpt'));
    $this->assertSession()->pageTextContains($this->t('The search result excerpted to show found search terms'));
    $this->assertSession()->pageTextContains($this->t('Relevance'));
    $this->assertSession()->pageTextContains($this->t('The relevance of this search result with respect to the query'));
    $this->assertSession()->pageTextContains($this->t('Language code'));
    $this->assertSession()->pageTextContains($this->t('The user language code.'));
    $this->assertSession()->pageTextContains($this->t('(No description available)'));
    $this->assertSession()->pageTextNotContains($this->t('Error: missing help'));

    // Then add some fields.
    $fields = [
      'views.counter',
      'search_api_datasource_database_search_index_entity_entity_test_mulrev_changed.id',
      'search_api_index_database_search_index.search_api_datasource',
      'search_api_datasource_database_search_index_entity_entity_test_mulrev_changed.body',
      'search_api_index_database_search_index.category',
      'search_api_index_database_search_index.keywords',
      'search_api_datasource_database_search_index_entity_entity_test_mulrev_changed.user_id',
      'search_api_entity_user.name',
      'search_api_entity_user.roles',
    ];
    $edit = [];
    foreach ($fields as $field) {
      $edit["name[$field]"] = $field;
    }
    $this->submitForm($edit, $this->t('Add and configure fields'));
    $this->assertSession()->statusCodeEquals(200);

    // @todo For some strange reason, the "roles" field form is not included
    //   automatically in the series of field forms shown to us by Views. Deal
    //   with this graciously (since it's not really our fault, I hope), but it
    //   would be great to have this working normally.
    $get_field_id = function ($key) {
      return Utility::splitPropertyPath($key, TRUE, '.')[1];
    };
    $fields = array_map($get_field_id, $fields);
    $fields = array_combine($fields, $fields);
    for ($i = 0; $i < count($fields); ++$i) {
      $field = $this->submitFieldsForm();
      if (!$field) {
        break;
      }
      unset($fields[$field]);
    }
    foreach ($fields as $field) {
      $this->drupalGet('admin/structure/views/nojs/handler/search_api_test_view/page_1/field/' . $field);
      $this->submitFieldsForm();
    }

    // Add click sorting for all fields where this is possible.
    $this->clickLink($this->t('Settings'), 0);
    $edit = [
      'style_options[info][search_api_datasource][sortable]' => 1,
      'style_options[info][category][sortable]' => 1,
      'style_options[info][keywords][sortable]' => 1,
    ];
    $this->submitForm($edit, $this->t('Apply'));

    // Add a filter for the "Name" field.
    $this->clickLink($this->t('Add filter criteria'));
    $edit = [
      'name[search_api_index_database_search_index.name]' => 'search_api_index_database_search_index.name',
    ];
    $this->submitForm($edit, $this->t('Add and configure filter criteria'));
    $this->submitPluginForm([]);

    // Save the view.
    $this->submitForm([], $this->t('Save'));
    $this->assertSession()->statusCodeEquals(200);

    // Check the results.
    $this->drupalGet('search-api-test');
    $this->assertSession()->statusCodeEquals(200);

    foreach ($this->entities as $id => $entity) {
      $fields = [
        'search_api_datasource',
        'id',
        'body',
        'category',
        'keywords',
        'user_id',
        'user_id:name',
        'user_id:roles',
      ];
      foreach ($fields as $field) {
        $field_entity = $entity;
        while (strpos($field, ':')) {
          list($direct_property, $field) = Utility::splitPropertyPath($field, FALSE);
          if (empty($field_entity->{$direct_property}[0]->entity)) {
            continue 2;
          }
          $field_entity = $field_entity->{$direct_property}[0]->entity;
        }
        // Check that both the English and the Dutch entity are present in the
        // results, with their correct field values.
        $entities = [$field_entity];
        if ($field_entity->hasTranslation('nl')) {
          $entities[] = $field_entity->getTranslation('nl');
        }
        foreach ($entities as $i => $field_entity) {
          if ($field != 'search_api_datasource') {
            $data = Utility::extractFieldValues($field_entity->get($field));
            if (!$data) {
              $data = ['[EMPTY]'];
            }
          }
          else {
            $data = ['entity:entity_test_mulrev_changed'];
          }
          $row_num = 2 * $id + $i - 1;
          $prefix = "#$row_num [$field] ";
          $text = $prefix . implode("|$prefix", $data);
          $this->assertSession()->pageTextContains($text);
        }
      }
    }

    // Check that click-sorting works correctly.
    $options = [
      'query' => [
        'order' => 'category',
        'sort' => 'asc',
      ],
    ];
    $this->drupalGet('search-api-test', $options);
    $this->assertSession()->statusCodeEquals(200);
    $ordered_categories = [
      '[EMPTY]',
      'article_category',
      'article_category',
      'dutch category 1',
      'dutch category 2',
      'dutch category 3',
      'dutch category 4',
      'dutch category 5',
      'item_category',
      'item_category',
    ];
    foreach ($ordered_categories as $i => $category) {
      ++$i;
      $this->assertSession()->pageTextContains("#$i [category] $category");
    }
    $options['query']['sort'] = 'desc';
    $this->drupalGet('search-api-test', $options);
    $this->assertSession()->statusCodeEquals(200);
    foreach (array_reverse($ordered_categories) as $i => $category) {
      ++$i;
      $this->assertSession()->pageTextContains("#$i [category] $category");
    }
  }

  /**
   * Submits the field handler config form currently displayed.
   *
   * @return string|null
   *   The field ID of the field whose form was submitted. Or NULL if the
   *   current page is no field form.
   */
  protected function submitFieldsForm() {
    $url_parts = explode('/', $this->getUrl());
    $field = array_pop($url_parts);
    if (array_pop($url_parts) != 'field') {
      return NULL;
    }

    $edit['options[fallback_options][multi_separator]'] = '|';
    $edit['options[alter][alter_text]'] = TRUE;
    $edit['options[alter][text]'] = "#{{counter}} [$field] {{ $field }}";
    $edit['options[empty]'] = "#{{counter}} [$field] [EMPTY]";

    switch ($field) {
      case 'counter':
        $edit = [
          'options[exclude]' => TRUE,
        ];
        break;

      case 'id':
        $edit['options[field_rendering]'] = FALSE;
        break;

      case 'search_api_datasource':
        unset($edit['options[fallback_options][multi_separator]']);
        break;

      case 'body':
        break;

      case 'category':
        break;

      case 'keywords':
        $edit['options[field_rendering]'] = FALSE;
        break;

      case 'user_id':
        $edit['options[field_rendering]'] = FALSE;
        $edit['options[fallback_options][display_methods][user][display_method]'] = 'id';
        break;

      case 'name':
        break;

      case 'roles':
        $edit['options[field_rendering]'] = FALSE;
        $edit['options[fallback_options][display_methods][user_role][display_method]'] = 'id';
        break;
    }

    $this->submitPluginForm($edit);

    return $field;
  }

  /**
   * Submits a Views plugin's configuration form.
   *
   * @param array $edit
   *   The values to set in the form.
   */
  protected function submitPluginForm(array $edit) {
    $button_label = $this->t('Apply');
    $buttons = $this->xpath('//input[starts-with(@value, :label)]', [':label' => $button_label->render()]);
    if ($buttons) {
      $button_label = $buttons[0]->getAttribute('value');
    }

    $this->submitForm($edit, $button_label);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Installs Drupal into the Simpletest site.
   *
   * We need to override \Drupal\Tests\BrowserTestBase::installDrupal() because
   * before modules install we need to add test entity bundles for this test.
   */
  public function installDrupal() {
    // Define information about the user 1 account.
    $this->rootUser = new UserSession(array(
      'uid' => 1,
      'name' => 'admin',
      'mail' => 'admin@example.com',
      'passRaw' => $this->randomMachineName(),
    ));

    // The child site derives its session name from the database prefix when
    // running web tests.
    $this->generateSessionName($this->databasePrefix);

    // Get parameters for install_drupal() before removing global variables.
    $parameters = $this->installParameters();

    // Prepare installer settings that are not install_drupal() parameters.
    // Copy and prepare an actual settings.php, so as to resemble a regular
    // installation.
    // Not using File API; a potential error must trigger a PHP warning.
    $directory = DRUPAL_ROOT . '/' . $this->siteDirectory;
    copy(DRUPAL_ROOT . '/sites/default/default.settings.php', $directory . '/settings.php');

    // All file system paths are created by System module during installation.
    // @see system_requirements()
    // @see TestBase::prepareEnvironment()
    $settings['settings']['file_public_path'] = (object) array(
      'value' => $this->publicFilesDirectory,
      'required' => TRUE,
    );
    $settings['settings']['file_private_path'] = (object) [
      'value' => $this->privateFilesDirectory,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    // Allow for test-specific overrides.
    $settings_testing_file = DRUPAL_ROOT . '/' . $this->originalSiteDirectory . '/settings.testing.php';
    if (file_exists($settings_testing_file)) {
      // Copy the testing-specific settings.php overrides in place.
      copy($settings_testing_file, $directory . '/settings.testing.php');
      // Add the name of the testing class to settings.php and include the
      // testing specific overrides.
      file_put_contents($directory . '/settings.php', "\n\$test_class = '" . get_class($this) . "';\n" . 'include DRUPAL_ROOT . \'/\' . $site_path . \'/settings.testing.php\';' . "\n", FILE_APPEND);
    }

    $settings_services_file = DRUPAL_ROOT . '/' . $this->originalSiteDirectory . '/testing.services.yml';
    if (!file_exists($settings_services_file)) {
      // Otherwise, use the default services as a starting point for overrides.
      $settings_services_file = DRUPAL_ROOT . '/sites/default/default.services.yml';
    }
    // Copy the testing-specific service overrides in place.
    copy($settings_services_file, $directory . '/services.yml');
    if ($this->strictConfigSchema) {
      // Add a listener to validate configuration schema on save.
      $content = file_get_contents($directory . '/services.yml');
      $services = Yaml::decode($content);
      $services['services']['simpletest.config_schema_checker'] = [
        'class' => ConfigSchemaChecker::class,
        'arguments' => ['@config.typed', $this->getConfigSchemaExclusions()],
        'tags' => [['name' => 'event_subscriber']]
      ];
      file_put_contents($directory . '/services.yml', Yaml::encode($services));
    }

    // Since Drupal is bootstrapped already, install_begin_request() will not
    // bootstrap into DRUPAL_BOOTSTRAP_CONFIGURATION (again). Hence, we have to
    // reload the newly written custom settings.php manually.
    Settings::initialize(DRUPAL_ROOT, $directory, $this->classLoader);

    // Execute the non-interactive installer.
    require_once DRUPAL_ROOT . '/core/includes/install.core.inc';
    install_drupal($parameters);

    // Import new settings.php written by the installer.
    Settings::initialize(DRUPAL_ROOT, $directory, $this->classLoader);
    foreach ($GLOBALS['config_directories'] as $type => $path) {
      $this->configDirectories[$type] = $path;
    }

    // After writing settings.php, the installer removes write permissions from
    // the site directory. To allow drupal_generate_test_ua() to write a file
    // containing the private key for drupal_valid_test_ua(), the site directory
    // has to be writable.
    // TestBase::restoreEnvironment() will delete the entire site directory. Not
    // using File API; a potential error must trigger a PHP warning.
    chmod($directory, 0777);

    // During tests, cacheable responses should get the debugging cacheability
    // headers by default.
    $this->setContainerParameter('http.response.debug_cacheability_headers', TRUE);

    $request = \Drupal::request();
    $this->kernel = DrupalKernel::createFromRequest($request, $this->classLoader, 'prod', TRUE);
    $this->kernel->prepareLegacyRequest($request);
    // Force the container to be built from scratch instead of loaded from the
    // disk. This forces us to not accidentally load the parent site.
    $container = $this->kernel->rebuildContainer();

    $config = $container->get('config.factory');

    // Manually create and configure private and temporary files directories.
    file_prepare_directory($this->privateFilesDirectory, FILE_CREATE_DIRECTORY);
    file_prepare_directory($this->tempFilesDirectory, FILE_CREATE_DIRECTORY);
    // While the temporary files path could be preset/enforced in settings.php
    // like the public files directory above, some tests expect it to be
    // configurable in the UI. If declared in settings.php, it would no longer
    // be configurable.
    $config->getEditable('system.file')
      ->set('path.temporary', $this->tempFilesDirectory)
      ->save();

    // Manually configure the test mail collector implementation to prevent
    // tests from sending out emails and collect them in state instead.
    // While this should be enforced via settings.php prior to installation,
    // some tests expect to be able to test mail system implementations.
    $config->getEditable('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // By default, verbosely display all errors and disable all production
    // environment optimizations for all tests to avoid needless overhead and
    // ensure a sane default experience for test authors.
    // @see https://www.drupal.org/node/2259167
    $config->getEditable('system.logging')
      ->set('error_level', 'verbose')
      ->save();
    $config->getEditable('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();

    // This will just set the Drupal state to include the necessary bundles for
    // our test entity type. Otherwise, fields from those bundles won't be found
    // and thus removed from the test index. (We can't do it in setUp(), before
    // calling the parent method, since the container isn't set up at that
    // point.)
    $bundles = array(
      'entity_test_mulrev_changed' => array('label' => 'Entity Test Bundle'),
      'item' => array('label' => 'item'),
      'article' => array('label' => 'article'),
    );
    \Drupal::state()->set('entity_test_mulrev_changed.bundles', $bundles);

    // Collect modules to install.
    $class = get_class($this);
    $modules = array();
    while ($class) {
      if (property_exists($class, 'modules')) {
        $modules = array_merge($modules, $class::$modules);
      }
      $class = get_parent_class($class);
    }
    if ($modules) {
      $modules = array_unique($modules);
      $success = $container->get('module_installer')->install($modules, TRUE);
      $this->assertTrue($success, SafeMarkup::format('Enabled modules: %modules', array('%modules' => implode(', ', $modules))));
      $this->rebuildContainer();
    }

    // Reset/rebuild all data structures after enabling the modules, primarily
    // to synchronize all data structures and caches between the test runner and
    // the child site.
    // Affects e.g. StreamWrapperManagerInterface::getWrappers().
    // @see \Drupal\Core\DrupalKernel::bootCode()
    // @todo Test-specific setUp() methods may set up further fixtures; find a
    //   way to execute this after setUp() is done, or to eliminate it entirely.
    $this->resetAll();
    $this->kernel->prepareLegacyRequest($request);
  }

}
