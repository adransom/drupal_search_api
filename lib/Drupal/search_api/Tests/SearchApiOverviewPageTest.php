<?php

/**
 * @file
 * Contains \Drupal\search_api\Tests\SearchApiOverviewPageTest.
 */

namespace Drupal\search_api\Tests;

use Drupal\search_api\Server\ServerInterface;

/**
 * Tests the Search API overview page.
 */
class SearchApiOverviewPageTest extends SearchApiWebTestBase {

  /**
   * The path of the overview page.
   *
   * @var string
   */
  protected $overviewPageUrl;

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Search API overview page tests',
      'description' => 'Test Search API overview page and what would be modified according to different server/index modifications.',
      'group' => 'Search API',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->drupalLogin($this->adminUser);

    $this->overviewPageUrl = 'admin/config/search/search-api';
  }

  /**
   * Tests the creation of a server and an index.
   */
  public function testServerAndIndexCreation() {
    // Test the creation of a server.
    $server = $this->getTestServer();

    $this->drupalGet($this->overviewPageUrl);

    $this->assertText($server->label(), 'Server present on overview page.');
    $this->assertRaw($server->get('description'), 'Description is present');
    $this->assertFieldByXPath('//tr[contains(@class,"' . $server->getEntityTypeId() . '-' . $server->id() . '")]//span[@class="search-api-entity-status-enabled"]', NULL, 'Server is in proper table');

    // Test the creation of an index.
    $index = $this->getTestIndex();

    $this->drupalGet($this->overviewPageUrl);

    $this->assertText($index->label(), 'Index present on overview page.');
    $this->assertRaw($index->get('description'), 'Index description is present');
    $this->assertFieldByXPath('//tr[contains(@class,"' . $index->getEntityTypeId() . '-' . $index->id() . '")]//span[@class="search-api-entity-status-enabled"]', NULL, 'Index is in proper table');
  }

  /**
   * Tests enable/disable operations for servers and indexes through the UI.
   */
  public function testServerAndIndexStatusChanges() {
    $server = $this->getTestServer();
    $this->assertEntityStatusChange($server);

    $index = $this->getTestIndex();
    $this->assertEntityStatusChange($index);

    // Disable the server and test that both itself and the index has been
    // disabled.
    $server->setStatus(FALSE)->save();
    $this->drupalGet($this->overviewPageUrl);
    $this->assertFieldByXPath('//tr[contains(@class,"' . $server->getEntityTypeId() . '-' . $server->id() . '")]//span[@class="search-api-entity-status-disabled"]', NULL, 'The server has been disabled.');
    $this->assertFieldByXPath('//tr[contains(@class,"' . $index->getEntityTypeId() . '-' . $index->id() . '")]//span[@class="search-api-entity-status-disabled"]', NULL, 'The index has been disabled.');

    // Test that an index can't be enabled if its server is disabled.
    $this->clickLink('enable', 1);
    $this->drupalGet($this->overviewPageUrl);
    $this->assertFieldByXPath('//tr[contains(@class,"' . $index->getEntityTypeId() . '-' . $index->id() . '")]//span[@class="search-api-entity-status-disabled"]', NULL, 'The index could not be enabled.');

    // Enable the server and try again.
    $server->setStatus(TRUE)->save();
    $this->drupalGet($this->overviewPageUrl);

    // This time the server is enabled so the first 'enable' link belongs to the
    // index.
    $this->clickLink('enable');
    $this->drupalGet($this->overviewPageUrl);
    $this->assertFieldByXPath('//tr[contains(@class,"' . $index->getEntityTypeId() . '-' . $index->id() . '")]//span[@class="search-api-entity-status-enabled"]', NULL, 'The index has been enabled.');

    // Create a new index without a server assigned and test that it can't be
    // enabled. The overview UI is not very consistent at the moment, so test
    // using API functions for now.
    $index2 = $this->getTestIndex('WebTest Index 2', 'webtest_index_2', NULL);
    $this->assertFalse($index2->status(), 'The newly created index without a server is disabled by default.');

    $index2->setStatus(TRUE)->save();
    $this->assertFalse($index2->status(), 'The newly created index without a server cannot be enabled.');
  }

  /**
   * Asserts enable/disable operations for a Search API server or index.
   *
   * @param \Drupal\search_api\Server\ServerInterface|\Drupal\search_api\Index\IndexInterface $entity
   *   A Search API server or index.
   */
  protected function assertEntityStatusChange($entity) {
    $this->drupalGet($this->overviewPageUrl);
    $this->assertFieldByXPath('//tr[contains(@class,"' . $entity->getEntityTypeId() . '-' . $entity->id() . '")]//span[@class="search-api-entity-status-enabled"]', NULL, 'The newly created entity is enabled by default.');

    // The first 'disable' link on the page belongs to our newly created server
    // and the second 'disable' link belongs to our newly created index.
    if ($entity instanceof ServerInterface) {
      $this->clickLink('disable');
    }
    else {
      $this->clickLink('disable', 1);
    }

    // Submit the confirmation form and test that entity has been disabled.
    $this->drupalPostForm(NULL, array(), 'Disable');
    $this->assertFieldByXPath('//tr[contains(@class,"' . $entity->getEntityTypeId() . '-' . $entity->id() . '")]//span[@class="search-api-entity-status-disabled"]', NULL, 'The entity has been disabled.');

    // Now enable the entity.
    $this->clickLink('enable');

    // And test that the enable operation succeeded.
    $this->drupalGet($this->overviewPageUrl);
    $this->assertFieldByXPath('//tr[contains(@class,"' . $entity->getEntityTypeId() . '-' . $entity->id() . '")]//span[@class="search-api-entity-status-enabled"]', NULL, 'The entity has benn enabled.');
  }

  /**
   * Tests index and server operations in the overview page.
   */
  public function testOperations() {
    /** @var $server \Drupal\search_api\Server\ServerInterface */
    $server = $this->getTestServer();

    $this->drupalGet($this->overviewPageUrl);
    $basic_url = $this->urlGenerator->generateFromRoute('search_api.server_view', array('search_api_server' => $server->id()));
    $this->assertRaw('<a href="' . $basic_url . '">canonical</a>', 'Canonical operation presents');
    $this->assertRaw('<a href="' . $basic_url . '/edit">edit-form</a>', 'Edit operation presents');
    $this->assertRaw('<a href="' . $basic_url . '/disable">disable</a>', 'Disable operation presents');
    $this->assertRaw('<a href="' . $basic_url . '/delete">delete-form</a>', 'Delete operation presents');
    $this->assertNoRaw('<a href="' . $basic_url . '/enable">enabled-form</a>', 'Enable operation is not present');

    $server->disable()->save();
    $this->drupalGet($this->overviewPageUrl);

    // As CsrfTokenGenerator uses current session Id we can not generate valid token
    $this->assertRaw('<a href="' . $basic_url .'/enable?token=', 'Enable operation present');
    $this->assertNoRaw('<a href="' . $basic_url .'/disable">disable-form</a>', 'Disable operation  is not present');
  }

}
