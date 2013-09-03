<?php

/**
 * @file
 * Contains \Drupal\search_api\Plugin\search_api\processor\Tokenizer.
 */

namespace Drupal\search_api\Plugin\search_api\processor;

use Drupal\Core\Annotation\Translation;
use Drupal\search_api\Annotation\SearchApiProcessor;
use Drupal\search_api\Plugin\Type\Processor\ProcessorPluginBase;

/**
 * Processor for tokenizing fulltext data by replacing (configurable)
 * non-letters with spaces.
 *
 * @SearchApiProcessor(
 *   id = "search_api_tokenizer",
 *   name = @Translation("Tokenizer"),
 *   description = @Translation("Tokenizes fulltext data by stripping whitespace. This processor allows you to specify which characters make up words and which characters should be ignored, using regular expression syntax. Otherwise it is up to the search server implementation to decide how to split indexed fulltext data."),
 *   weight = 20
 * )
 */
class Tokenizer extends ProcessorPluginBase {

  /**
   * @var string
   */
  protected $spaces;

  /**
   * @var string
   */
  protected $ignorable;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, array &$form_state) {
    $form = parent::buildConfigurationForm();

    // Only make fulltext fields available as options.
    $fields = $this->index->getFields();
    $field_options = array();
    foreach ($fields as $name => $field) {
      if (empty($field['real_type']) && search_api_is_text_type($field['type'])) {
        $field_options[$name] = $field['name'];
      }
    }
    $form['fields']['#options'] = $field_options;

    $form += array(
      'spaces' => array(
        '#type' => 'textfield',
        '#title' => t('Whitespace characters'),
        '#description' => t('Specify the characters that should be regarded as whitespace and therefore used as word-delimiters. ' .
            'Specify the characters as a <a href="@link">PCRE character class</a>. ' .
            'Note: For non-English content, the default setting might not be suitable.',
            array('@link' => url('http://www.php.net/manual/en/regexp.reference.character-classes.php'))),
        '#default_value' => "[^[:alnum:]]",
      ),
      'ignorable' => array(
        '#type' => 'textfield',
        '#title' => t('Ignorable characters'),
        '#description' => t('Specify characters which should be removed from fulltext fields and search strings (e.g., "-"). The same format as above is used.'),
        '#default_value' => "[']",
      ),
    );

    if (!empty($this->options)) {
      $form['spaces']['#default_value'] = $this->options['spaces'];
      $form['ignorable']['#default_value'] = $this->options['ignorable'];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, array &$form_state) {
    parent::validateConfigurationForm($form, $values, $form_state);

    $spaces = str_replace('/', '\/', $values['spaces']);
    $ignorable = str_replace('/', '\/', $values['ignorable']);
    if (@preg_match('/(' . $spaces . ')+/u', '') === FALSE) {
      $el = $form['spaces'];
      form_error($el, $el['#title'] . ': ' . t('The entered text is no valid regular expression.'));
    }
    if (@preg_match('/(' . $ignorable . ')+/u', '') === FALSE) {
      $el = $form['ignorable'];
      form_error($el, $el['#title'] . ': ' . t('The entered text is no valid regular expression.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function processFieldValue(&$value) {
    $this->prepare();
    if ($this->ignorable) {
      $value = preg_replace('/(' . $this->ignorable . ')+/u', '', $value);
    }
    if ($this->spaces) {
      $arr = preg_split('/(' . $this->spaces . ')+/u', $value);
      if (count($arr) > 1) {
        $value = array();
        foreach ($arr as $token) {
          $value[] = array('value' => $token);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function process(&$value) {
    // We don't touch integers, NULL values or the like.
    if (is_string($value)) {
      $this->prepare();
      if ($this->ignorable) {
        $value = preg_replace('/' . $this->ignorable . '+/u', '', $value);
      }
      if ($this->spaces) {
        $value = preg_replace('/' . $this->spaces . '+/u', ' ', $value);
      }
    }
  }

  protected function prepare() {
    if (!isset($this->spaces)) {
      $this->spaces = str_replace('/', '\/', $this->options['spaces']);
      $this->ignorable = str_replace('/', '\/', $this->options['ignorable']);
    }
  }

}
