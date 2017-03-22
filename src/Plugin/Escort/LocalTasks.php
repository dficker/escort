<?php

namespace Drupal\escort\Plugin\Escort;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Element;

/**
 * Provides a "Local Tasks" escort to display the local tasks.
 *
 * @Escort(
 *   id = "local_tasks",
 *   admin_label = @Translation("Local Tasks"),
 *   category = @Translation("Menu"),
 * )
 */
class LocalTasks extends EscortPluginBase implements ContainerFactoryPluginInterface {
  use EscortPluginLinkTrait;

  /**
   * {@inheritdoc}
   */
  protected $usesIcon = FALSE;

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Creates a LocalTasksEscort instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\LocalTaskManagerInterface $local_task_manager
   *   The local task manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LocalTaskManagerInterface $local_task_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->localTaskManager = $local_task_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.menu.local_task'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label_display' => FALSE,
      'primary' => TRUE,
      'secondary' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#icon' => 'fa-th-list',
      '#markup' => $this->label(TRUE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function escortBuildMultiple() {
    $build = [];
    $cacheability = new CacheableMetadata();
    $tabs = $this->buildTasks($cacheability);

    $cacheability->applyTo($build);

    foreach ($tabs as $key => $tab) {
      $build[$key] = $this->buildLink($tab['#link']['title'], $tab['#link']['url'], $tab['#attributes']);
      $build[$key]['#weight'] = $tab['#weight'];
    }
    return $build;
  }

  /**
   * Build list of tasks.
   */
  protected function buildTasks($cacheability) {
    $config = $this->configuration;
    $primary = [];
    $secondary = [];

    // Add only selected levels for the printed output.
    if ($config['primary']) {
      $links = $this->localTaskManager->getLocalTasks($this->routeMatch->getRouteName(), 0);
      $cacheability = $cacheability->merge($links['cacheability']);
      // Do not display single tabs.
      $primary = count(Element::getVisibleChildren($links['tabs'])) > 1 ? $links['tabs'] : [];
    }
    if ($config['secondary']) {
      $links = $this->localTaskManager->getLocalTasks($this->routeMatch->getRouteName(), 1);
      $cacheability = $cacheability->merge($links['cacheability']);
      // Do not display single tabs.
      $secondary = count(Element::getVisibleChildren($links['tabs'])) > 1 ? $links['tabs'] : [];
    }

    $tabs = [];
    foreach ($primary as $key => $tab) {
      $tab['#attributes']['class'][] = 'primary-tab';
      $tabs[$key] = $tab;
      if (!empty($tab['#active']) && !empty($secondary)) {
        foreach ($secondary as $secondary_key => $secondary_tab) {
          $secondary_tab['#attributes']['class'][] = 'secondary-tab';
          $secondary_tab['#weight'] = $tab['#weight'];
          $tabs[$secondary_key] = $secondary_tab;
        }
      }
    }

    // Add in secondary tabs in case primary tabs are empty.
    $tabs += $secondary;
    return $tabs;
  }

  /**
   * {@inheritdoc}
   */
  public function escortForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;
    $defaults = $this->defaultConfiguration();

    $form['levels'] = [
      '#type' => 'details',
      '#title' => $this->t('Shown tabs'),
      '#description' => $this->t('Select tabs being shown in the escort'),
      '#open' => $defaults['primary'] !== $config['primary'] || $defaults['secondary'] !== $config['secondary'],
    ];
    $form['levels']['primary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show primary tabs'),
      '#default_value' => $config['primary'],
    ];
    $form['levels']['secondary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show secondary tabs'),
      '#default_value' => $config['secondary'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function escortSubmit($form, FormStateInterface $form_state) {
    $levels = $form_state->getValue('levels');
    $this->configuration['primary'] = $levels['primary'];
    $this->configuration['secondary'] = $levels['secondary'];
  }

}
