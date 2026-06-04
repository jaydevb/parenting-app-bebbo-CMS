<?php

declare(strict_types=1);

namespace Drupal\bebbo_api_security\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin configuration form for API security settings.
 */
class ApiSecuritySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['bebbo_api_security.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'bebbo_api_security_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bebbo_api_security.settings');

    // Enforcement Mode.
    $form['enforcement'] = [
      '#type' => 'details',
      '#title' => $this->t('Enforcement Mode'),
      '#open' => TRUE,
    ];
    $form['enforcement']['enforcement_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Mode'),
      '#options' => [
        'disabled' => $this->t('Disabled — no JWT checking'),
        'grace_period' => $this->t('Grace period — log but allow all requests'),
        'enforced' => $this->t('Enforced — reject requests without valid JWT'),
      ],
      '#default_value' => $config->get('enforcement_mode'),
    ];
    $form['enforcement']['dev_bypass_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable developer bypass'),
      '#description' => $this->t('Allow specific IPs to skip JWT validation.'),
      '#default_value' => $config->get('dev_bypass_enabled'),
    ];
    $form['enforcement']['dev_bypass_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Bypass IP addresses'),
      '#description' => $this->t('One IP per line. These IPs skip JWT validation.'),
      '#default_value' => $config->get('dev_bypass_ips'),
      '#states' => [
        'visible' => [
          ':input[name="dev_bypass_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Google Play Integrity.
    $form['google'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Play Integrity'),
      '#open' => TRUE,
    ];
    $form['google']['google_package_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Package name'),
      '#description' => $this->t('Android app package name (e.g., org.unicef.bebbo).'),
      '#default_value' => $config->get('google_package_name'),
    ];
    $form['google']['google_project_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project number'),
      '#description' => $this->t('Google Cloud project number.'),
      '#default_value' => $config->get('google_project_number'),
    ];
    $form['google']['google_verdict_freshness_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Verdict freshness (seconds)'),
      '#description' => $this->t('Maximum age of an integrity verdict.'),
      '#default_value' => $config->get('google_verdict_freshness_seconds'),
      '#min' => 60,
      '#max' => 3600,
    ];

    // Apple App Attest.
    $form['apple'] = [
      '#type' => 'details',
      '#title' => $this->t('Apple App Attest'),
    ];
    $form['apple']['apple_team_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Team ID'),
      '#description' => $this->t('10-character alphanumeric Apple Team ID.'),
      '#default_value' => $config->get('apple_team_id'),
      '#maxlength' => 10,
    ];
    $form['apple']['apple_bundle_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle ID'),
      '#description' => $this->t('iOS app bundle identifier.'),
      '#default_value' => $config->get('apple_bundle_id'),
    ];
    $form['apple']['apple_production_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Production mode'),
      '#description' => $this->t('Uncheck for development/sandbox testing.'),
      '#default_value' => $config->get('apple_production_mode'),
    ];

    // Token Lifetimes.
    $form['tokens'] = [
      '#type' => 'details',
      '#title' => $this->t('Token Lifetimes'),
      '#open' => TRUE,
    ];
    $form['tokens']['jwt_expiry_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('JWT expiry (seconds)'),
      '#default_value' => $config->get('jwt_expiry_seconds'),
      '#min' => 300,
      '#max' => 86400,
    ];
    $form['tokens']['refresh_expiry_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh token expiry (seconds)'),
      '#default_value' => $config->get('refresh_expiry_seconds'),
      '#min' => 86400,
      '#max' => 7776000,
    ];
    $form['tokens']['refresh_rotation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable refresh token rotation'),
      '#description' => $this->t('Issue new refresh token on each use. Recommended for security.'),
      '#default_value' => $config->get('refresh_rotation_enabled'),
    ];

    // Rate Limiting.
    $form['rate_limiting'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate Limiting'),
      '#open' => TRUE,
    ];
    $form['rate_limiting']['register_rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Registration limit (per device per hour)'),
      '#default_value' => $config->get('register_rate_limit'),
      '#min' => 1,
      '#max' => 100,
    ];
    $form['rate_limiting']['refresh_rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh limit (per device per hour)'),
      '#default_value' => $config->get('refresh_rate_limit'),
      '#min' => 1,
      '#max' => 200,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $team_id = $form_state->getValue('apple_team_id');
    if (!empty($team_id) && !preg_match('/^[A-Z0-9]{10}$/', $team_id)) {
      $form_state->setErrorByName('apple_team_id', $this->t('Apple Team ID must be 10 alphanumeric characters.'));
    }

    $bypass_ips = $form_state->getValue('dev_bypass_ips');
    if (!empty($bypass_ips)) {
      foreach (array_filter(array_map('trim', explode("\n", $bypass_ips))) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
          $form_state->setErrorByName('dev_bypass_ips', $this->t('@ip is not a valid IP address.', ['@ip' => $ip]));
          break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $this->config('bebbo_api_security.settings')
      ->set('enforcement_mode', $form_state->getValue('enforcement_mode'))
      ->set('dev_bypass_enabled', (bool) $form_state->getValue('dev_bypass_enabled'))
      ->set('dev_bypass_ips', $form_state->getValue('dev_bypass_ips'))
      ->set('google_package_name', $form_state->getValue('google_package_name'))
      ->set('google_project_number', $form_state->getValue('google_project_number'))
      ->set('google_verdict_freshness_seconds', (int) $form_state->getValue('google_verdict_freshness_seconds'))
      ->set('apple_team_id', $form_state->getValue('apple_team_id'))
      ->set('apple_bundle_id', $form_state->getValue('apple_bundle_id'))
      ->set('apple_production_mode', (bool) $form_state->getValue('apple_production_mode'))
      ->set('jwt_expiry_seconds', (int) $form_state->getValue('jwt_expiry_seconds'))
      ->set('refresh_expiry_seconds', (int) $form_state->getValue('refresh_expiry_seconds'))
      ->set('refresh_rotation_enabled', (bool) $form_state->getValue('refresh_rotation_enabled'))
      ->set('register_rate_limit', (int) $form_state->getValue('register_rate_limit'))
      ->set('refresh_rate_limit', (int) $form_state->getValue('refresh_rate_limit'))
      ->save();
  }

}
