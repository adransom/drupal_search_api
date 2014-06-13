<?php

/**
 * @file
 * Contains \Drupal\search_api\Task\ServerTaskManager.
 */

namespace Drupal\search_api\Task;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\search_api\Exception\SearchApiException;
use Drupal\search_api\Index\IndexInterface;
use Drupal\search_api\Server\ServerInterface;

/**
 * Provides the state system using a key value store.
 */
class ServerTaskManager implements ServerTaskManagerInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entity_manager;

  /**
   * Creates a new server task manager.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   */
  function __construct(Connection $database, EntityManagerInterface $entity_manager) {
    $this->database = $database;
    $this->entity_manager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ServerInterface $server = NULL) {
    $select = $this->database->select('search_api_task', 't');
    $select->fields('t')
      // Only retrieve tasks we can handle.
      ->condition('t.type', array('addIndex', 'updateIndex', 'removeIndex', 'deleteItems', 'deleteAllIndexItems'));
    if ($server) {
      if (!$server->status()) {
        return FALSE;
      }
      $select->condition('t.server_id', $server->id());
    }
    else {
      // By ordering by the server, we can later just load them when we reach
      // them while looping through the tasks. It is very unlikely there will be
      // tasks for more than one or two servers, so a *_load_multiple() probably
      // wouldn't bring any significant advantages, but complicate the code.
      $select->orderBy('t.server_id');
    }

    // Sometimes the order of tasks might be important, so make sure to order by
    // the task ID (which should be in order of insertion).
    $select->orderBy('t.id');
    $tasks = $select->execute();

    $executed_tasks = array();
    $failing_servers = array();
    foreach ($tasks as $task) {
      if (isset($failing_servers[$task->server_id])) {
        continue;
      }
      if (!$server || $server->id() != $task->server_id) {
        $server = $this->loadServer($task->server_id);
        if (!$server) {
          $failing_servers[$task->server_id] = TRUE;
          continue;
        }
      }
      if (!$server->status()) {
        continue;
      }
      $index = NULL;
      if ($task->index_id) {
        $index = $this->loadIndex($task->index_id);
      }
      try {
        switch ($task->type) {
          case 'addIndex':
            if ($index) {
              $server->getBackend()->addIndex($index);
            }
            break;

          case 'updateIndex':
            if ($index) {
              if ($task->data) {
                $index->original = unserialize($task->data);
              }
              $server->getBackend()->updateIndex($index);
            }
            break;

          case 'removeIndex':
            if ($index) {
              $server->getBackend()->removeIndex($index ? $index : $task->index_id);
              $this->delete(NULL, $server, $index);
            }
            break;

          case 'deleteItems':
            if ($index && !$index->isReadOnly()) {
              $ids = unserialize($task->data);
              $server->getBackend()->deleteItems($index, $ids);
            }
            break;

          case 'deleteAllIndexItems':
            if ($index && !$index->isReadOnly()) {
              $server->getBackend()->deleteAllIndexItems($index);
            }
            break;

          default:
            // This should never happen.
            continue;
        }
        $executed_tasks[] = $task->id;
      }
      catch (SearchApiException $e) {
        // If a task fails, we don't want to execute any other tasks for that
        // server (since order might be important).
        watchdog_exception('search_api', $e);
        $failing_servers[$task->server_id] = TRUE;
      }
    }

    // Delete all successfully executed tasks.
    if ($executed_tasks) {
      $this->delete($executed_tasks);
    }
    // Return TRUE if no tasks failed (i.e., if we didn't mark any server as
    // failing).
    return (bool) $failing_servers;
  }

  /**
   * {@inheritdoc}
   */
  public function add(ServerInterface $server, $type, IndexInterface $index = NULL, $data = NULL) {
    $this->database->insert('search_api_task')
      ->fields(array(
        'server_id' => $server->id(),
        'type' => $type,
        'index_id' => $index ? (is_object($index) ? $index->id() : $index) : NULL,
        'data' => isset($data) ? serialize($data) : NULL,
      ))
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $ids = NULL, ServerInterface $server = NULL, $index = NULL) {
    $delete = $this->database->delete('search_api_task');
    if ($ids) {
      $delete->condition('id', $ids);
    }
    if ($server) {
      $delete->condition('server_id', $server->id());
    }
    if ($index) {
      $delete->condition('index_id', $index instanceof IndexInterface ? $index->id() : $index);
    }
    $delete->execute();
  }

  /**
   * Loads a search server.
   *
   * @param string $server_id
   *   The server's machine name.
   *
   * @return \Drupal\search_api\Server\ServerInterface|null
   *   The loaded server, or NULL if it could not be loaded.
   */
  protected function loadServer($server_id) {
    return $this->entity_manager->getStorage('search_api_server')->load($server_id);
  }

  /**
   * Loads a search index.
   *
   * @param string $index_id
   *   The index's machine name.
   *
   * @return \Drupal\search_api\Index\IndexInterface|null
   *   The loaded index, or NULL if it could not be loaded.
   */
  protected function loadIndex($index_id) {
    return $this->entity_manager->getStorage('search_api_index')->load($index_id);
  }

}
