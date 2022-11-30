<?php

namespace Drupal\rest_toolkit;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Drupal\rest_toolkit\Normalizer\RestTypedDataNormalizer;
use Symfony\Component\Serializer\Serializer;
use Drupal\Component\Utility\Xss;
use Drupal\image\Entity\ImageStyle;

/**
 * Provides helper methods for logging with dependency injection.
 */
trait RestToolkitEndpointTrait {

  /**
   * The current user.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   */
  protected $user;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   *   The HTTP request object.
   */
  public $request;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  public $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  public $serializerFormats = [];

  /**
   * The request format (xml, csv, yaml, json, etc).
   *
   * @var string
   */
  protected $format;

  /**
   * Current pager index.
   *
   * @var int
   */
  // protected $page;

  /**
   * Range for query, using pager.
   *
   * @var array
   */
  protected $range;

  /**
   * Current sort column.
   *
   * @var int
   */
  protected $sort;

  /**
   * Common, default columns.
   *
   * @var array
   */
  protected $defaultColumns;

  /**
   * Textfield input to filter results by. In most cases, will search body.
   *
   * @var string
   */
  protected $filter;

  /**
   * Data included in POST and PATCH request bodies.
   *
   * @var array
   */
  public $postData = [];

  /**
   * Data included in POST and PATCH request bodies.
   *
   * @var array
   */
  public $normalizerContext = [];

  /**
   * [$mimetypeGuesser description]
   *
   * @var [type]
   */
  //protected $mimetypeGuesser;

  /**
   * Constructs a new ManagementTablesController object.
   */
  public function __construct(Request $request) {
    $this->user = \Drupal::currentUser();
    // The reqeust information.
    $this->request = $request;
    $formats = ['json'];
    $encoders = [new JsonEncoder()];
    //$normalizers = [new TypedDataNormalizer()];
    $normalizers = [new RestTypedDataNormalizer()];
    $serializer = new Serializer($normalizers, $encoders);

    $this->serializer = $serializer;
    $this->serializerFormats = $formats;

    // $this->page = intval($this->request->query->get('page')) ?? 0;

    $this->range = [
      'start' => $this->request->query->get('start') ?? 0,
      // 'end' => $this->page + 50,
      'end' => $this->request->query->get('count') ?? 50,
    ];

    // Sorting, useful in combination with paging.
    $this->sort = [];
    if (!empty($this->request->query->get('sort_by')) &&
        !empty($this->request->query->get('sort_order'))) {
      $this->sort = [
        'sort_by' => $this->request->query->get('sort_by'),
        'sort_order' => $this->request->query->get('sort_order'),
      ];
    }

    $this->filter = $this->request->query->get('filter') ?? NULL;

    // Columns that all tables will use.
    $this->defaultColumns = [
      'created',
    ];

    $this->format = $this->getRequestFormat($this->request);

    // If POST/PATCH request with body, fill postData.
    if (in_array($this->request->getMethod(), ['POST', 'PATCH'])) {
      $this->postData = $this->serializer->decode($this->request->getContent(), $this->format);
    }

    $this->normalizerContext = [];
    if (!empty($this->request->query->get('expand_entities'))) {
      $this->normalizerContext['expand_entities'] = explode(',', $this->request->query->get('expand_entities'));
    }
    // $this->mimetypeGuesser = \Drupal::service('file.mime_type.guesser');
  }

  /**
  * {@inheritdoc}
  */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Gets the format of the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The format of the request.
   */
  public function getRequestFormat(Request $request) {
    $format = $request->getRequestFormat();
    // Try to handle flat html requests this time.
    if ($format == 'html') {
      return $format;
    }
    // Unrecognized format, no go.
    if (!in_array($format, $this->serializerFormats)) {
      throw new BadRequestHttpException("Unrecognized format: {$format}.");
    }
    return $format;
  }

  /**
   * Sends a response.
   *
   * @param      array           $response  The response
   *
   * @return     Response|array  ( description_of_the_return_value )
   */
  public function sendResponse($response) {
    if ($this->format == 'html') {
      return $response;
    }
    else {
      $status = Response::HTTP_OK;
      // If there is no result, send HTTP 204.
      if (empty($response)) {
        $status = Response::HTTP_NO_CONTENT;
        $response = ['message' => 'No results found.'];
      }

      $encodedResponse = $this->serializer->encode($response, $this->format, $this->normalizerContext);
      return new Response($encodedResponse, $status);
    }
  }

  /**
   * Normalize entities and encode for endpoints.
   *
   * @param      array           $response  The response
   *
   * @return     Response|array  ( description_of_the_return_value )
   */
  public function sendNormalizedResponse($response, $stopFields = []) {
    // If stopFields are passed, remove them from the end response.
    if (!empty($stopFields)) {
      // For objects passed (like an entity).
      if (is_object($response)) {
        foreach ($stopFields as $stopField) {
          unset($response->{$stopField});
        }
      }
      // For arrays passed.
      else if (is_array($response)) {
        foreach ($stopFields as $stopField) {
          unset($response[$stopField]);
        }
      }
    }

    if ($this->format == 'html') {
      return $response;
    }
    else {
      $status = Response::HTTP_OK;
      // If there is no result, send HTTP 204.
      if (empty($response)) {
        $status = Response::HTTP_NO_CONTENT;
        $response = ['message' => 'No results found.'];
      }

      $encodedResponse = $this->serializer->serialize($response, $this->format, $this->normalizerContext);

      // $encodedResponse = $this->serializer->encode($response, $this->format);
      return new Response($encodedResponse, $status);
    }
  }

  public function normalizeEntity($entity, $andDecodeIt = FALSE) {
    $return = $this->serializer->serialize($entity, $this->format, $this->normalizerContext);

    if ($andDecodeIt) {
      $return = json_decode($return, TRUE);
    }

    return $return;
  }

  /**
   * { function_description }
   */
  public function deNormalizePostData($saveEntity = FALSE) {
    // $data = $this->serializer->denormalize($this->postData, 'Drupal\node\Entity\Node', $this->format);

    // If we failed to inform of entity_type.
    if (empty($this->postData['entity_type'])) {
      $this->send400('No entity type given.');
    }

    // Get the manager for this entity_type.
    $entityTypeManager = \Drupal::entityTypeManager()->getStorage($this->postData['entity_type']);

    if (!empty($this->postData['id'])) {
      $entity = $entityTypeManager->load($this->postData['id']);
      if (empty($entity)) {
        // If we couldn't load the entity.
        $message = 'Could not load ' . $this->postData['entity_type'] .
        ' ID: ' . $this->postData['id'];
        return $this->send400($message);
      }
      // Now set the bundle, this should be a PATCH or DELETE request.
      $this->postData['type'] = $entity->bundle();
    }
    else {
      // Initial scaffold of the entity.
      $entity = $entityTypeManager->create([
        'type' => $this->postData['type'],
        // 'title' => $this->postData['title'],
      ]);

      // Find out the key used as this entity's label.
      $labelKey = \Drupal::entityTypeManager()->getDefinition($this->postData['entity_type'])->getKey('label');

      // See what label field to use.
      if ($labelKey) {
        $entity->{$labelKey} = $this->postData[$labelKey];
      }
      elseif ($entity->hasField('title')) {
        $entity->title = $this->postData['title'];
      }
      elseif ($entity->hasField('name')) {
        $entity->name = $this->postData['name'];
      }
    }

    // Get all the field definitions for this entity type & bundle (type).
    $fieldDefinitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($this->postData['entity_type'], $this->postData['type']);

    // Don't bother with this below, registers as entity_reference.
    unset($this->postData['type']);

    // Find the intersections of what is being POSTed and what the entity has.
    $fieldsToUse = array_intersect_key($fieldDefinitions, $this->postData);
    // \Drupal::service('inspect.inspect')->variable($fieldsToUse)->log();

    foreach($fieldsToUse as $fieldName => $fieldDefinition) {
      $fieldType = $fieldDefinition->getType();

      /*\Drupal::service('inspect.inspect')->variable($fieldName)->log();
      \Drupal::service('inspect.inspect')->variable($fieldType)->log();*/

      // inspect('fieldType: ' . $fieldType . ' name: ' . $fieldName);
      switch ($fieldType) {

        // case 'address':
        /*'country_code' => 'US',
        'administrative_area' => 'CA',
        'locality' => 'Mountain View',
        'postal_code' => '94043',
        'address_line1' => '1098 Alta Ave',
        'organization' => 'Google Inc.',
        'given_name' => 'John',
        'family_name' => 'Smith',*/
          // break;
        //
        case 'commerce_price':
          if (empty($this->postData[$fieldName])) {
            $this->postData[$fieldName] = '0';
          }
          // $price = new \Drupal\commerce_price\Price((string) $this->postData[$fieldName], 'USD');

          // Handle JS object of { number: 222, currency_code: 'USD' }
          if (is_array($this->postData[$fieldName])) {
            // Also handle null and other things being sent.
            if (empty($this->postData[$fieldName]['number'])) {
              $this->postData[$fieldName]['number'] = '0';
            }

            if (empty($this->postData[$fieldName]['currency_code'])) {
              $this->postData[$fieldName]['currency_code'] = 'USD';
            }

            $number = $this->postData[$fieldName]['number'];
            $currency_code = $this->postData[$fieldName]['currency_code'];
          }
          else {
            // Otherwise, default to USD (could check Global Commerce config).
            $number = $this->postData[$fieldName];
            $currency_code = 'USD';
          }

          // Finally, format data for the field.
          $price = new \Drupal\commerce_price\Price((string) $number, $currency_code);

          $entity->{$fieldName} = $price;

          break;

        default:
          $entity->{$fieldName}->setValue($this->postData[$fieldName]);
          break;
      }
    }

    // If we're actually creating an entity for this request.
    if ($saveEntity) {
      try {
        $entity->save();
      }
      catch (\Throwable $e) {
        /*inspect("CAUGHT THROWABLE");
        inspect(get_class_methods($e));*/
        if (stristr($e->getMessage(), 'Duplicate entry')) {
          $this->send400('Duplicate ' . $this->postData['entity_type'] . ' already exists.');
        }
        else {
          $this->send400($e->getMessage());
        }
      }

    }

    return $entity;
  }

  /**
   * Send 400 error.
   *
   * @param string $message.
   *
   * @return string
   *   The format of the request.
   */
  public function send400($message = 'Client error.') {
    // Throw a 400..
    // inspect($this->postData);
    throw new BadRequestHttpException($message);
  }

  /**
   * Send 403 error.
   *
   * @param string $message.
   *
   * @return string
   *   The format of the request.
   */
  public function send403($message = 'Client error.') {
    // Throw a 400..
    // inspect($this->postData);
    throw new AccessDeniedHttpException($message);
  }

  /**
   * Debug something.
   *
   * @param mixed $var.
   *
   */
  public function debug($var = '') {
    $debug = print_r($var, TRUE);
    $output = 'DEBUG: <pre>' . $debug . '</pre>';
    \Drupal::logger('rest_toolkit')->debug($output);
  }

}
