<?php

namespace Drupal\aquila\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides an example block.
 *
 * @Block(
 *   id = "aquila_example",
 *   admin_label = @Translation("Example"),
 *   category = @Translation("aquila")
 * )
 */
class ExampleBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build['content'] = [
      '#markup' => $this->t('It works!'),
    ];
    return $build;
  }

}
