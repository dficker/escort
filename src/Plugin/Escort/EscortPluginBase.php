<?php

namespace Drupal\escort\Plugin\Escort;

use Drupal\Core\Plugin\ContextAwarePluginBase;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\escort\Entity\EscortInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines a base escort implementation that most escorts plugins will extend.
 *
 * This abstract class provides the generic escort configuration form, default
 * escort settings, and handling for general user-defined escort visibility
 * settings.
 *
 * @ingroup escort_api
 */
abstract class EscortPluginBase extends ContextAwarePluginBase implements EscortPluginInterface, PluginWithFormsInterface {

  use ContextAwarePluginAssignmentTrait;
  use RefinableCacheableDependencyTrait;
  use PluginWithFormsTrait;

  /**
   * The escort entity this plugin belongs to.
   *
   * @var \Drupal\escort\Entity\EscortInterface
   */
  protected $escort;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Whether the escort support a base icon.
   *
   * @var bool
   */
  protected $usesIcon = TRUE;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function label($force_admin = FALSE) {
    if (!$force_admin && !empty($this->configuration['label'])) {
      return $this->configuration['label'];
    }

    $definition = $this->getPluginDefinition();
    // Cast the admin label to a string since it is an object.
    // @see \Drupal\Core\StringTranslation\TranslatableMarkup
    return (string) $definition['admin_label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->baseConfigurationDefaults(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * Returns generic default configuration for escort plugins.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    $defaults = array(
      'id' => $this->getPluginId(),
      'label' => '',
      'provider' => $this->pluginDefinition['provider'],
    );
    if ($this->usesIcon()) {
      $defaults['icon'] = '';
    }
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfigurationValue($key, $value) {
    $this->configuration[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    $access = $this->escortAccess($account);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Indicates whether the escort should be shown.
   *
   * Escorts with specific access checking should override this method rather
   * than access(), in order to avoid repeating the handling of the
   * $return_as_object argument.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user session for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   *
   * @see self::access()
   */
  protected function escortAccess(AccountInterface $account) {
    // By default, the escort is visible.
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $definition = $this->getPluginDefinition();
    $form['provider'] = [
      '#type' => 'value',
      '#value' => $definition['provider'],
    ];

    $form['admin_label'] = [
      '#type' => 'item',
      '#title' => $this->t('Escort Type'),
      '#plain_text' => $definition['admin_label'],
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin Label'),
      '#maxlength' => 255,
      '#default_value' => $this->label(),
      '#required' => TRUE,
    ];

    if ($this->usesIcon()) {
      $form['icon'] = [
        '#type' => 'micon',
        '#title' => $this->t('Icon'),
        '#default_value' => $this->configuration['icon'],
      ];
    }

    // Add context mapping UI form elements.
    $contexts = $form_state->getTemporaryValue('gathered_contexts') ?: [];
    $form['context_mapping'] = $this->addContextAssignmentElement($this, $contexts);

    // Add plugin-specific settings for this escort type.
    $form += $this->escortForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function escortForm($form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Remove the admin_label form item element value so it will not persist.
    $form_state->unsetValue('admin_label');

    $this->escortValidate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function escortValidate($form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Process the escort's submission handling if no errors occurred only.
    if (!$form_state->getErrors()) {
      $this->configuration['label'] = $form_state->getValue('label');
      $this->configuration['provider'] = $form_state->getValue('provider');
      if ($this->usesIcon()) {
        $this->configuration['icon'] = $form_state->getValue('icon');
      }
      $this->escortSubmit($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function escortSubmit($form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function build() {
    return $this->escortBuildMultiple();
  }

  /**
   * Create an escort with multiple items.
   *
   * By default it will output a single item from escortBuild().
   *
   * @return array
   *   An array of renderable elements.
   */
  protected function escortBuildMultiple() {
    return [
      $this->escortBuild(),
    ];
  }

  /**
   * Create a single item of an escort.
   *
   * @return array
   *   The renderable array representing a single escort item.
   */
  protected function escortBuild() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setEscort(EscortInterface $escort) {
    $this->escort = $escort;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEscort() {
    return $this->escort;
  }

  /**
   * Checks if plugin defines an icon to use.
   *
   * @return bool
   *   True if icon should be used.
   */
  protected function usesIcon() {
    return $this->usesIcon;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    $definition = $this->getPluginDefinition();
    $admin_label = $definition['admin_label'];

    // @todo This is basically the same as what is done in
    //   \Drupal\system\MachineNameController::transliterate(), so it might make
    //   sense to provide a common service for the two.
    $transliterated = $this->transliteration()->transliterate($admin_label, LanguageInterface::LANGCODE_DEFAULT, '_');
    $transliterated = Unicode::strtolower($transliterated);

    $transliterated = preg_replace('@[^a-z0-9_.]+@', '', $transliterated);

    return $transliterated;
  }

  /**
   * Wraps the transliteration service.
   *
   * @return \Drupal\Component\Transliteration\TransliterationInterface
   *   The transliteration service.
   */
  protected function transliteration() {
    if (!$this->transliteration) {
      $this->transliteration = \Drupal::transliteration();
    }
    return $this->transliteration;
  }

  /**
   * Sets the transliteration service.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   */
  public function setTransliteration(TransliterationInterface $transliteration) {
    $this->transliteration = $transliteration;
  }

  /**
   * {@inheritdoc}
   */
  public function getBodyAttributes($is_admin) {
    return [];
  }

}
