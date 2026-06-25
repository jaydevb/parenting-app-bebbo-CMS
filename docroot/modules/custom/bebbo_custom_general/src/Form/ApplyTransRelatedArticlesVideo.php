<?php

namespace Drupal\bebbo_custom_general\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\bebbo_custom_general\ApplyNodeTranslations;

/**
 * Admin form to propagate related articles/videos across node translations.
 */
class ApplyTransRelatedArticlesVideo extends FormBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'apply_trans_related_articles_video';
  }

  /**
   * Force update check build form.
   *
   * @param array $form
   *   The custom form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The custom form state.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $content_types = ['article' => 'Article', 'video_article' => 'Video Article'];
    /* Dropdown Select. */
    $form['content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $content_types,
    ];

    /* Add a submit button. */
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Submit the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    ApplyNodeTranslations::initiateBatchProcessing($form_state->getValue('content_types'));
  }

}
