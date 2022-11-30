<?php

/**
 * @file
 * Contains \Drupal\rest_toolkit\Plugin\views\style\SerializerCount.
 */
namespace Drupal\rest_toolkit\Plugin\views\style;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\rest\Plugin\views\style\Serializer;

/** The style plugin for serialized output formats.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "serializer_count",
 *   title = @Translation("Serializer with count"),
 *   help = @Translation("Serializes views row data using the Serializer component and adds a count."),
 *   display_types = {"data"}
 * )
 */

class SerializerCount extends Serializer {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    // $options['single_as_object'] = FALSE;
    // $options['single_without_pager'] = FALSE;

    $options['single_as_object'] = ['default' => FALSE];
    $options['single_without_pager'] = ['default' => FALSE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['single_as_object'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Single result as object (not array)'),
      '#description' => $this->t('If only 1 result in row, send it flat.'),
      '#default_value' => $this->options['single_as_object'],
    );

    $form['single_without_pager'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Single result remove pager object'),
      '#description' => $this->t('If only 1 result in row, send it flat.'),
      '#default_value' => $this->options['single_without_pager'],
    );


  }

  /**
   * {@inheritdoc}
   */
  /*public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    $formats = $form_state->getValue(['style_options', 'formats']);
    $form_state->setValue(['style_options', 'formats'], array_filter($formats));
  }*/


  /**
   * {@inheritdoc}
   */
  public function render() {
    $rows = array();
    // !!! Enable some Exposed options in Pager settings.

    $pager = $this->view->getPager();
    $count = $this->view->pager->getTotalItems();
    $items_per_page = $this->view->pager->options['items_per_page'];
    $pages = 0;
    // Prevent division by zero notice.
    if ($items_per_page) {
      $pages = ceil($count / $items_per_page);
    }
    $current_page = $this->view->pager->getCurrentPage();

    // If the Data Entity row plugin is used, this will be an array of entities
    // which will pass through Serializer to one of the registered Normalizers,
    // which will transform it to arrays/scalars. If the Data field row plugin
    // is used, $rows will not contain objects and will pass directly to the
    // Encoder.
    foreach ($this->view->result as $row_index => $row) {
      $this->view->row_index = $row_index;
      $rows[] = $this->view->rowPlugin->render($row);
    }

    unset($this->view->row_index);

    // Get the content type configured in the display or fallback to the
    // default.
    if ((empty($this->view->live_preview))) {
      $content_type = $this->displayHandler->getContentType();
    }
    else {
      $content_type = !empty($this->options['formats']) ? reset($this->options['formats']) : 'json';
    }


    $rowCount = count($rows);
    if ($rowCount == 1 || $rowCount == 0) {
      if ($this->options['single_as_object']) {
        // If at least 1 row, otherwise we still need blank array.
        if ($rowCount) {
          $rows = reset($rows);
        }
      }

      if ($this->options['single_without_pager']) {
        $ret = $this->serializer->serialize(
          $rows,
          $content_type
        );

        return $ret;
      }

    }

    // For exposed filters.
    // Go through each handler and let it generate its exposed widget.
    // foreach ($this->view->display_handler->handlers as $type => $value) {
    //   /** @var \Drupal\views\Plugin\views\ViewsHandlerInterface $handler */
    //   foreach ($this->view->$type as $id => $handler) {
    //     if ($handler->canExpose() && $handler->isExposed()) {
    //       // Grouped exposed filters have their own forms.
    //       // Instead of render the standard exposed form, a new Select or
    //       // Radio form field is rendered with the available groups.
    //       // When an user choose an option the selected value is split
    //       // into the operator and value that the item represents.
    //       dpm($handler->value, 'handlez');
    //       if ($handler->isAGroup()) {

    //         /*$handler->groupForm($form, $form_state);
    //         $id = $handler->options['group_info']['identifier'];*/

    //       }
    //       else {
    //         //$handler->buildExposedForm($form, $form_state);
    //       }
    //       /*if ($info = $handler->exposedInfo()) {
    //         $form['#info']["$type-$id"] = $info;
    //       }*/
    //     }
    //   }
    // }

      /*$viewUrlParameters = $this->view->getDisplay()->getUrl()->getRouteParameters();
          dpm($viewUrlParameters, 'urlParams');*/

    // Finalize the return.
    $ret = $this->serializer->serialize(
      [
        'results' => $rows,
        'pager' => [
          'count' => intval($count),
          'pages' => intval($pages),
          'items_per_page' => intval($items_per_page),
          'current_page' => intval($current_page),
        ]
      ],
      $content_type
    );

    return $ret;
  }
}
