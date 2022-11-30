<?php

namespace Drupal\rest_toolkit\Plugin\views\display;

use Drupal\Core\Render\RenderContext;
use Drupal\Component\Utility\Html;
use Drupal\rest\Plugin\views\display;
use Drupal\views\Render\ViewsRenderPipelineMarkup;

/**
 * The plugin that handles Data response callbacks for REST resources.
 *
 * @ingroup views_display_plugins
 *
 * @ViewsDisplay(
 *   id = "rest_export_nested_object",
 *   title = @Translation("REST export nested {obj}"),
 *   help = @Translation("Create a REST export resource which supports nested JSON and Josh's SeralizerCount style - Views output as an object, not array at root."),
 *   uses_route = TRUE,
 *   admin = @Translation("REST export nested {obj}"),
 *   returns_response = TRUE
 * )
 */
class RestExportNestedObject extends display\RestExport {

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = [];
    $build['#markup'] = $this->renderer->executeInRenderContext(new RenderContext(), function() {
      return $this->view->style_plugin->render();
    });

    // Decode results.
    $results = \GuzzleHttp\json_decode($build['#markup']);

    // For our customized serializer.
    $isObject = FALSE;
    $iterateMe = [];
    $iterateMe = $results;

    $viewStyle = $this->view->getStyle();
    // dpm($viewStyle->options['single_as_object'], 'view style');
    if (!empty($viewStyle->options['single_as_object'])) {
      $iterateMe = [$results];
    }

    if (is_object($iterateMe)) {
      $iterateMe = [];
      if (!empty($results->results)) {
        $iterateMe = $results->results;
      }
      $isObject = TRUE;
    }

    // Loop through results and fields.
    foreach ($iterateMe as $key => $result) {
      foreach ($result as $property => $value) {
        // Check if the field can be decoded using PHP's json_decode().
        if (is_string($value)) {
          if (json_decode($value) !== NULL) {
            // If so, use Guzzle to decode the JSON and add it to the results.
            $iterateMe[$key]->$property = \GuzzleHttp\json_decode($value);
          }
          elseif (json_decode(Html::decodeEntities($value)) !== NULL){
            $iterateMe[$key]->$property = \GuzzleHttp\json_decode(Html::decodeEntities($value));
          }
        }
        // Special null handling.
        if (is_string($value) && $value === 'null') {
          $iterateMe[$key]->$property = NULL;
        }
      }
    }

    // Now put it back in results.
    if ($isObject) {
      $results->results = $iterateMe;
    }

    // Convert back to JSON.
    $build['#markup'] = \GuzzleHttp\json_encode($results);

    $this->view->element['#content_type'] = $this->getMimeType();
    $this->view->element['#cache_properties'][] = '#content_type';

    // Encode and wrap the output in a pre tag if this is for a live preview.
    if (!empty($this->view->live_preview)) {
      $build['#prefix'] = '<pre>';
      $build['#plain_text'] = $build['#markup'];
      $build['#suffix'] = '</pre>';
      unset($build['#markup']);
    }
    elseif ($this->view->getRequest()->getFormat($this->view->element['#content_type']) !== 'html') {
      // This display plugin is primarily for returning non-HTML formats.
      // However, we still invoke the renderer to collect cacheability metadata.
      // Because the renderer is designed for HTML rendering, it filters
      // #markup for XSS unless it is already known to be safe, but that filter
      // only works for HTML. Therefore, we mark the contents as safe to bypass
      // the filter. So long as we are returning this in a non-HTML response
      // (checked above), this is safe, because an XSS attack only works when
      // executed by an HTML agent.
      // @todo Decide how to support non-HTML in the render API in
      //   https://www.drupal.org/node/2501313.
      $build['#markup'] = ViewsRenderPipelineMarkup::create($build['#markup']);
    }

    parent::applyDisplayCachablityMetadata($build);

    return $build;
  }

}
