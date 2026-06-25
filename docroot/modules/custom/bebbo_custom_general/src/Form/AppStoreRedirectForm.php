<?php

namespace Drupal\bebbo_custom_general\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure app store redirect URLs for QR code landing page.
 */
class AppStoreRedirectForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['bebbo_custom_general.app_store_redirect'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pb_custom_form_app_store_redirect';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('bebbo_custom_general.app_store_redirect');

    $form['app_store_url'] = [
      '#type' => 'url',
      '#title' => $this->t('App Store listing'),
      '#description' => $this->t('e.g. https://apps.apple.com/app/bebbo-parenting-app/id1588918146'),
      '#default_value' => $config->get('app_store_url'),
      '#maxlength' => 500,
    ];

    $form['google_play_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Google Play listing'),
      '#description' => $this->t('e.g. https://play.google.com/store/apps/details?id=org.unicef.ecar.bebbo'),
      '#default_value' => $config->get('google_play_url'),
      '#maxlength' => 500,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('bebbo_custom_general.app_store_redirect')
      ->set('app_store_url', $form_state->getValue('app_store_url'))
      ->set('google_play_url', $form_state->getValue('google_play_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
