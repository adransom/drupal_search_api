<?php

/**
 * @file
 * Contains \Drupal\search_api\Plugin\search_api\processor\StopWords.
 */

namespace Drupal\search_api\Plugin\search_api\processor;

use Drupal\Core\Annotation\Translation;
use Drupal\search_api\Annotation\SearchApiProcessor;
use Drupal\search_api\Plugin\Type\Processor\ProcessorPluginBase;
use Drupal\search_api\Plugin\search_api\query\DefaultQuery;

/**
 * Processor for removing stopwords from index and search terms.
 *
 * @SearchApiProcessor(
 *   id = "search_api_stopwords",
 *   name = @Translation("Stopwords"),
 *   description = @Translation("This processor prevents certain words from being indexed and removes them from search terms. For best results, it should only be executed after tokenizing."),
 *   weight = 30
 * )
 */
class StopWords extends ProcessorPluginBase {

  /**
   * Holds all words ignored for the last query.
   *
   * @var array
   */
  protected $ignored = array();

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, array &$form_state) {
    $form = parent::buildConfigurationForm();

    $form += array(
      'help' => array(
        '#markup' => '<p>' . t('Provide a stopwords file or enter the words in this form. If you do both, both will be used. Read about !stopwords.', array('!stopwords' => l(t('stop words'), "http://en.wikipedia.org/wiki/Stop_words"))) . '</p>',
      ),
      'file' => array(
        '#type' => 'textfield',
        '#title' => t('Stopwords file URI'),
        '#title' => t('Enter the URI of your stopwords.txt file'),
        '#description' => t('This must be a stream-type description like <code>public://stopwords/stopwords.txt</code> or <code>http://example.com/stopwords.txt</code> or <code>private://stopwords.txt</code>.'),
      ),
      'stopwords' => array(
        '#type' => 'textarea',
        '#title' => t('Stopwords'),
        '#description' => t('Enter a space and/or linebreak separated list of stopwords that will be removed from content before it is indexed and from search terms before searching.'),
        '#default_value' => t("but\ndid\nthe this that those\netc"),
      ),
    );

    if (!empty($this->options)) {
      $form['file']['#default_value'] = $this->options['file'];
      $form['stopwords']['#default_value'] = $this->options['stopwords'];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, array &$form_state) {
    parent::validateConfigurationForm($form, $values, $form_state);

    $stopwords = trim($values['stopwords']);
    $uri = $values['file'];
    if (empty($stopwords) && empty($uri)) {
      $el = $form['file'];
      form_error($el, $el['#title'] . ': ' . t('At stopwords file or words are required.'));
    }
    if (!empty($uri) && !file_get_contents($uri)) {
      $el = $form['file'];
      form_error($el, t('Stopwords file') . ': ' . t('The file %uri is not readable or does not exist.', array('%uri' => $uri)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function process(&$value) {
    $stopwords = $this->getStopWords();
    if (empty($stopwords) && !is_string($value)) {
      return;
    }
    $words = preg_split('/\s+/', $value);
    foreach ($words as $sub_key => $sub_value) {
      if (isset($stopwords[$sub_value])) {
        unset($words[$sub_key]);
        $this->ignored[] = $sub_value;
      }
    }
    $value = implode(' ', $words);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(DefaultQuery $query) {
    $this->ignored = array();
    parent::preprocessSearchQuery($query);
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(array &$response, DefaultQuery $query) {
    if ($this->ignored) {
      if (isset($response['ignored'])) {
        $response['ignored'] = array_merge($response['ignored'], $this->ignored);
      }
      else {
        $response['ignored'] = $this->ignored;
      }
    }
  }

  /**
   * @return
   *   An array whose keys are the stopwords set in either the file or the text
   *   field.
   */
  protected function getStopWords() {
    if (isset($this->stopwords)) {
      return $this->stopwords;
    }
    $file_words = $form_words = array();
    if (!empty($this->options['file']) && $stopwords_file = file_get_contents($this->options['file'])) {
      $file_words = preg_split('/\s+/', $stopwords_file);
    }
    if (!empty($this->options['stopwords'])) {
      $form_words = preg_split('/\s+/', $this->options['stopwords']);
    }
    $this->stopwords = array_flip(array_merge($file_words, $form_words));
    return $this->stopwords;
  }
}
