<?php
// $Id$

/**
 * Search service class using Solr server.
 */
class SearchApiSolrService extends SearchApiAbstractService {

  /**
   * A connection to the Solr server.
   *
   * @var SearchApiSolrConnection
   */
  protected $solr;

  /**
   * An array of all recognized types mapped to a prefix used for identifying
   * them in the Solr schema.
   *
   * @var array
   */
  protected static $type_prefixes = array(
    'text' => 't',
    'tokens' => 't',
    'string' => 's',
    'integer' => 'i',
    'decimal' => 'f',
    'date' => 'd',
    'duration' => 'i',
    'boolean' => 'b',
    'uri' => 's',
  );

  public function configurationForm() {
    if (empty($this->options)) {
      // First configuring this server
      $options = array(
        'http_user' => '',
        'http_pass' => '',
      );
      $form['host'] = array(
        '#type' => 'textfield',
        '#title' => t('Solr host'),
        '#description' => t('The host name or IP of your Solr server, e.g. <code>localhost</code> or <code>www.example.com</code>.'),
        '#default_value' => 'localhost',
        '#required' => TRUE,
      );
      $form['port'] = array(
        '#type' => 'textfield',
        '#title' => t('Solr port'),
        '#description' => t('The Jetty example server is at port 8983, while Tomcat uses 8080 by default.'),
        '#default_value' => '8983',
        '#required' => TRUE,
      );
      $form['path'] = array(
        '#type' => 'textfield',
        '#title' => t('Solr path'),
        '#description' => t('The path that identifies the Solr instance to use on the server.'),
        '#default_value' => '/solr',
      );
    }
    else {
      // Editing this server
      $options = $this->options;
      $form['host'] = array(
        '#type' => 'value',
        '#value' => $options['host'],
      );
      $form['port'] = array(
        '#type' => 'value',
        '#value' => $options['port'],
      );
      $form['path'] = array(
        '#type' => 'value',
        '#value' => $options['path'],
      );
      $url = 'http://' . $options['host'] . ':' . $options['port'] . $options['path'];
      $form['server_description'] = array(
        '#type' => 'item',
        '#title' => t('Solr server URI'),
        '#value' => l($url, $url),
      );
    }

    $form['http_user'] = array(
      '#type' => 'textfield',
      '#title' => t('Basic HTTP authentication'),
      '#description' => t('If your Solr server is protected by basic HTTP authentication, enter the used username here.'),
      '#default_value' => $options['http_user'],
    );
    $form['http_pass'] = array(
      '#type' => 'password',
      '#description' => t('Enter the corresponding HTTP authentication password.'),
      '#default_value' => $options['http_pass'],
    );

    return $form;
  }

  public function configurationFormValidate(array $form, array &$values, $prefix = '') {
    if (!is_numeric($values['port']) || $values['port'] < 0 || $values['port'] > 65535) {
      form_set_error($prefix . 'port', t('The port has to be an integer between 0 and 65535.'));
    }
  }

  /**
   * Create a connection to the Solr server as configured in $this->options.
   */
  protected function connect() {
    if (!$this->solr) {
      $this->solr = new SearchApiSolrConnection($this->options);
    }
  }

  public function removeIndex($index) {
    $id = is_object($index) ? $index->id : $index;
    try {
      $this->connect();
      $this->solr->deleteByQuery("index_id:" . $id);
    }
    catch (Exception $e) {
      watchdog('search_api_solr', t("An error occurred while deleting an index' data: !msg.", array('!msg' => $e->getMessage())));
    }
  }

  public function indexItems(SearchApiIndex $index, array $items) {
    $documents = array();
    $ret = array();
    $index_id = $index->id;
    $fields = $this->getFieldNames($index);

    foreach ($items as $id => $item) {
      try {
        $doc = new Apache_Solr_Document();
        $doc->setField('id', $this->createId($index_id, $id));
        $doc->setField('index_id', $index_id);
        $doc->setField('item_id', $id);

        foreach ($item as $key => $field) {
          if (!isset($fields[$key])) {
            throw new SearchApiException(t('Unknown field !field.', array('!field' => $key)));
          }
          $this->addIndexField($doc, $fields[$key], $field['value'], $field['type']);
        }

        $documents[] = $doc;
        $ret[] = $id;
      }
      catch (Exception $e) {
        watchdog('search_api_solr', t('An error occurred while indexing: !msg.', array('!msg' => $e->getMessage())));
      }
    }

    if (!$documents) {
      return array();
    }
    try {
      $this->connect();
      $response = $this->solr->addDocuments($documents);
      if ($response->getHttpStatus() == 200) {
        return $ret;
      }
      throw new SearchApiException(t('HTTP status !status: !msg.',
          array('!status' => $response->getHttpStatus(), '!msg' => $response->getHttpStatusMessage())));
    }
    catch (Exception $e) {
      watchdog('search_api_solr', t('An error occurred while indexing: !msg.', array('!msg' => $e->getMessage())));
    }
    return array();
  }

  /**
   * Creates an ID used as the unique identifier at the Solr server. This has to
   * consist of both index and item ID.
   */
  protected function createId($index_id, $item_id) {
    return "$index_id-$item_id";
  }

  /**
   * Create a list of all indexed field names mapped to their Solr field names.
   * The special fields "search_api_id" and "search_api_relevance" are also
   * included.
   */
  protected function getFieldNames(SearchApiIndex $index) {
    $ret = array(
      'search_api_id' => 'is_search_api_id',
      'search_api_relevance' => 'score',
    );
    if (empty($index->options['fields'])) {
      return $ret;
    }
    $fields = $index->options['fields'];
    foreach ($fields as $key => $field) {
      if (empty($field['indexed'])) {
        continue;
      }
      $type = $field['type'];
      $inner_type = search_api_extract_inner_type($type);
      $pref = self::$type_prefixes[$inner_type];
      if ($pref != 't') {
        $pref .= $type == $inner_type ? 's' : 'm';
      }
      $name = $pref . '_' . $key;
      $ret[$key] = $name;
    }
    return $ret;
  }

  /**
   * Helper method for indexing.
   * Add $field with field name $key to the document $doc. The format of $field
   * is the same as specified in SearchApiServiceInterface::indexItems().
   */
  protected function addIndexField(Apache_Solr_Document $doc, $key, $value, $type, $multi_valued = FALSE) {
    if (search_api_is_list_type($type)) {
      $type = substr($type, 5, -1);
      foreach ($value as $v) {
        $this->addIndexField($doc, $key, $v, $type, TRUE);
      }
      return;
    }
    switch ($type) {
      case 'tokens':
        foreach ($value as $v) {
          $doc->addField($key, $v['value']);
        }
        break;
      case 'date':
        $value = is_numeric($value) ? (int) $value : strtotime($value);
        if ($value === FALSE) {
          return;
        }
        $value = format_date($value, 'custom', 'Y-m-d\TH:i:s\Z', 'UTC');
        // Fall-through

      default:
        if ($multi_valued) {
          $doc->addField($key, $value);
        }
        else {
          $doc->setField($key, $value);
        }
    }
  }

  /**
   * This method has a custom, Solr-specific extension:
   * If $ids is a string other than "all", it is treated as a Solr query. All
   * items matching that Solr query are then deleted. If $index is additionally
   * specified, then only those items also lying on that index will be deleted.
   * It is up to the caller to ensure $ids is a valid query when the method is
   * called in this fashion.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    $this->connect();
    if ($index) {
      $index_id = $index->id;
      if (is_array($ids)) {
        $solr_ids = array();
        foreach ($ids as $id) {
          $solr_ids[] = $this->createId($index_id, $id);
        }
        $this->solr->deleteByMultipleIds($solr_ids);
      }
      elseif ($ids == 'all') {
        $this->solr->deleteByQuery("index_id:" . $index_id);
      }
      else {
        $this->solr->deleteByQuery("index_id:" . $index_id . ' (' . $ids . ')');
      }
    }
    else {
      $q = $ids == 'all' ? '*:*' : $ids;
      $this->solr->deleteByQuery($q);
    }
  }

  public function search(SearchApiQueryInterface $query) {
    $time_method_called = microtime(TRUE);
    // Get field information
    $index = $query->getIndex();
    $fields = $this->getFieldNames($index);

    // Extract keys
    $keys = $query->getKeys();
    if (is_array($keys)) {
      $keys = $this->flattenKeys($keys);
    }

    // Set searched fields
    $options = $query->getOptions();
    $search_fields = $query->getFields();
    $qf = array();
    if ($search_fields === NULL) {
      foreach ($index->options['fields'] as $f => $field) {
        if (!empty($field['indexed']) && search_api_is_text_type($field['type'])) {
          $qf[] = $fields[$f];
        }
      }
    }
    else {
      foreach ($search_fields as $f) {
        $qf[] = $fields[$f];
      }
    }

    // Extract filters
    $filter = $query->getFilter();
    $fq = $this->createFilterQueries($filter, $fields);

    // Extract sort
    $sort = array();
    foreach ($query->getSort() as $f => $order) {
      $f = $fields[$f];
      $order = strtolower($order);
      $sort[] = "$f $order";
    }

    // Set defaults
    if (!$keys) {
      $keys = '*:*';
    }
    $offset = isset($options['offset']) ? $options['offset'] : 0;
    $limit = isset($options['limit']) ? $options['limit'] : 1000000;

    // Collect parameters
    $params = array(
      'qf' => $qf,
    );
    if ($fq) {
      $params['fq'] = $fq;
    }
    if ($sort) {
      $params['sort'] = implode(', ', $sort);
    }

    try {
      $time_processing_done = microtime(TRUE);
      $this->connect();
      $response = $this->solr->search($keys, $offset, $limit, $params);
      $time_query_done = microtime(TRUE);

      if ($response->getHttpStatus() != 200) {
        throw new SearchApiException(t('The Solr server responded with status code !status: !msg.',
            array('!status' => $response->getHttpStatus(), '!msg' => $response->getHttpStatusMessage())));
      }

      $results = array();
      $results['result count'] = $response->response->numFound;
      $results['results'] = array();
      foreach ($response->response->docs as $doc) {
        $doc->id = $doc->item_id;
        unset($doc->item_id);
        foreach ($doc as $k => $v) {
          $result[$k] = $v;
        }
        $results['results'][$doc->id] = $result;
      }

      $time_end = microtime(TRUE);
      $results['performance'] = array(
        'complete' => $time_end - $time_method_called,
        'preprocessing' => $time_processing_done - $time_method_called,
        'execution' => $time_query_done - $time_processing_done,
        'postprocessing' => $time_end - $time_query_done,
      );

      return $results;
    }
    catch (Exception $e) {
      throw new SearchApiException($e->getMessage());
    }
  }

  /**
   * Flatten a keys array into a single search string.
   *
   * @param array $keys
   *   The keys array to flatten, formatted as specified by
   *   SearchApiQueryInterface::getKeys().
   *
   * @return string
   *   A Solr query string representing the same keys.
   */
  protected function flattenKeys(array $keys) {
    $k = array();
    foreach (element_children($keys) as $i) {
      $key = $keys[$i];
      if (!$key) {
        continue;
      }
      if (is_array($key)) {
        $k[] = $this->flattenKeys($key);
      }
      else {
        $key = trim($key);
        if (strpos($key, ' ') !== FALSE) {
          $key = SearchApiSolrConnection::phrase($key);
        }
        else {
          $key = SearchApiSolrConnection::escape($key);
        }
        $k[] = $key;
      }
    }
    if (!$k) {
      return '';
    }
    if ($keys['#conjunction'] == 'OR') {
      $k = '((' . implode(') OR (', $k) . '))';
      return empty($keys['#negation']) ? $k : '-' . $k;
    }

    $k = implode(' ', $k);
    return empty($keys['#negation']) ? $k : '-(' . $k . ')';
  }

  /**
   * Transforms a query filter into a flat array of Solr filter queries, using
   * the field names in $fields.
   */
  protected function createFilterQueries(SearchApiQueryFilterInterface $filter, array $fields) {
    $or = $filter->getConjunction() == 'OR';
    $fq = array();
    foreach ($filter->getFilters() as $f) {
      if (is_array($f)) {
        $fq[] = $this->createFilterQuery($fields[$f[0]], $f[1], $f[2]);
      }
      else {
        $f = $this->createFilterQueries($f, $fields);
        if ($filter->getConjunction() != $f->getConjunction()) {
          // $or == TRUE means the nested filter has conjunction AND, and vice versa
          $sep = $or ? ' ' : ' OR ';
          $fq[] = '((' . implode(')' . $sep . '(', $f) . '))';
        }
        else {
          $fq = array_merge($fq, $f);
        }
      }
    }
    return $or ? array('((' . implode(') OR (', $fq) . '))') : $fq;
  }

  /**
   * Create a single search query string according to the given field, value
   * and operator.
   */
  protected function createFilterQuery($field, $value, $operator) {
    $field = SearchApiSolrConnection::escape($field);
    $value = trim($value);
    $value = strpos($value, ' ') === FALSE ? SearchApiSolrConnection::escape($value) : SearchApiSolrConnection::phrase($value);
    switch ($operator) {
      case '<>':
        return "-($field:$value)";
      case '<':
        return "$field:{* TO $value}";
      case '<=':
        return "$field:[* TO $value]";
      case '>=':
        return "$field:[$value TO *]";
      case '>':
        return "$field:{{$value} TO *}";

      default:
        return "$field:$value";
    }
  }

  //
  // Additional methods that might be used when knowing the service class.
  //

  /**
   * Ping the Solr server to tell whether it can be accessed. Uses the
   * admin/ping request handler.
   */
  public function ping() {
    $this->connect();
    return $this->solr->ping();
  }

}