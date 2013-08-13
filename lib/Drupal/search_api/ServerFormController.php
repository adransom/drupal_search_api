<?php

/**
 * @file
 * Contains \Drupal\search_api\ServerFormController.
 */

namespace Drupal\search_api;

use Drupal\Core\Entity\EntityFormController;

/**
 * Provides a form controller for search server forms.
 */
class ServerFormController extends EntityFormController {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, array &$form_state) {
    $form['test'] = array(
      '#title' => 'Test',
      '#type' => 'textfield',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, array &$form_state) {
    parent::validate($form, $form_state);
  }

}