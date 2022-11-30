<?php

namespace Drupal\rest_toolkit\Normalizer;

use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\serialization\Normalizer\NormalizerBase;

/**
 * Converts typed data objects to arrays.
 */
class RestTypedDataNormalizer extends NormalizerBase {

  public $serializer;
  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = TypedDataInterface::class;

/*  public function supportsDenormalization($data, $type, $format = NULL) {
    return TRUE;
  }*/

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    $this->addCacheableDependency($context, $object);
    // \Drupal::service('inspect.inspect')->variable($context)->log();
    /*if (isset($value[0]) && isset($value[0]['value'])) {
      $value = $value[0]['value'];
    }*/

    // Fields that should not be sent, sensitive data, etc.
    $protectedFields = [
      'pass',
      'mail',
      'init',
      'roles',
      'access',
      'login'
    ];

    $value = [];
    $expandEntity = FALSE;
    $fieldDefinition = $object->getFieldDefinition();
    $fieldType = $fieldDefinition->getType();
    $fieldName = $fieldDefinition->getName();

    // Stop now on sensitive fields.
    if (in_array($fieldName, $protectedFields)) {
      // Only if the user isn't the owner of the entity.
      if (empty($context['is_owner']) || $fieldName == 'pass') {
        return [];
      }
    }

    switch ($fieldType) {
      case 'entity_reference':
      case 'entity_reference_revisions':
        $context['expand_non_array'] = FALSE;
        // If it is not a custom field_, too many entity_ref properties cause too
        // much recursion.
        if (!empty($context['expand_entities']) &&
          in_array($fieldName, $context['expand_entities'])) {
          $expandEntity = TRUE;
        }

        // Always expand commerce_product_attribute_values.
        if (strpos($fieldName, 'attribute_') === 0) {
          $expandEntity = TRUE;
          $context['expand_non_array'] = TRUE;
        }

        if (!$expandEntity && strpos($fieldName, 'field_') !== 0) {
          $value = $object->getValue();
          // Support for stringable value objects: avoid numerous custom normalizers.
          if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
          }
          break;
          // return $value;
        }

        // $settings = $object->getFieldDefinition()->getSettings();
        $refs = $object->referencedEntities();
        // Type of entity being referenced.
        // $settings['target_type'];

        if (!empty($refs)) {
          if ($object->entity instanceof \Drupal\Core\Entity\ContentEntityBase) {
            // If it is content, we can serialize it.
            foreach ($refs as $ref) {
              if ($context['expand_non_array']) {
                $value = $this->serializer->normalize($ref, $format, $context);
              }
              else {
                $value[] = $this->serializer->normalize($ref, $format, $context);

              }
            }
          }
          else {
            $value = $object->getValue();
            // Support for stringable value objects: avoid numerous custom normalizers.
            /*if (is_object($value) && method_exists($value, '__toString')) {
              $value = (string) $value;
            }*/
          }
        }

        break;

      case 'image':
      // if (strstr($object->entity->getMimeType(), 'image')) {

        $refs = $object->referencedEntities();
        // Type of entity being referenced.
        // $settings['target_type'];

        if (!empty($refs)) {
          if ($object->entity instanceof \Drupal\Core\Entity\ContentEntityBase) {
            // If it is content, we can serialize it.
            foreach ($refs as $ref) {
              $values = $this->serializer->normalize($ref, $format);
              $values['uri'] = [
                'uri' => $values['uri'],
                'url' => $ref->createFileUrl(TRUE),
              ];
              $values['url'] = $ref->createFileUrl(false);
              $value[] = $values;
            }
          }
          else {
            $value = $object->getValue();
            // Support for stringable value objects: avoid numerous custom normalizers.
            /*if (is_object($value) && method_exists($value, '__toString')) {
              $value = (string) $value;
            }*/
          }
        }

        //$fileUri = $object->getFileUri();

        /*$sizes = [
          // 'original' => $file->url(),
          'original' => ImageStyle::load('orient_only')->buildUrl($fileUri),*/

      break;

      case 'file':
        $refs = $object->referencedEntities();
        // Type of entity being referenced.
        // $settings['target_type'];

        if (!empty($refs)) {
          if ($object->entity instanceof \Drupal\Core\Entity\ContentEntityBase) {
            // If it is content, we can serialize it.
            foreach ($refs as $ref) {
              $values = $this->serializer->normalize($ref, $format);
              $values['uri'] = [
                'uri' => $values['uri'],
                'url' => $ref->createFileUrl(TRUE),
              ];
              $values['url'] = $ref->createFileUrl(false);
              $value[] = $values;
            }
          }
          else {
            $value = $object->getValue();
          }
        }
        break;

      case 'integer':
        $value = $object->getValue();
        $value = $this->recursiveResetArray($value);
        foreach ($value as &$val) {
          $val = intval($val);
        }
        break;

      case 'commerce_price':
        $value = $object->getValue();
        foreach ($value as &$val) {
          $val['number'] = round((float) $val['number'], 2);
          // $val['number'] = doubleval(number_format($val['number'], 2, '.', ''));
        }
        break;

      // @Todo - Evaluate.  From recent e-commerce work.
      case 'commerce_adjustment':
        $objVal = $object->getValue();
        if ($objVal != NULL) {
          foreach ($objVal as $val) {
            if (is_array($val)) {
              foreach ($val as $adjVal) {
                $adjustmentArray = $adjVal->toArray();
                $adjustmentArray['amount'] = $adjustmentArray['amount']->toArray();
                $adjustmentArray['amount']['number'] = round((float) $adjustmentArray['amount']['number'], 2);
                $value[] = $adjustmentArray;
              }
            }
          }
        }
        else {
          $value[] = NULL;
        }
        break;

      case 'boolean':
        $value = $object->getValue();
        $value = $this->recursiveResetArray($value);
        foreach ($value as &$val) {
          $val = (bool) $val;
        }
        break;

      /*case 'commerce_product_attribute_value':
        $value = $object->getName();
        break;*/

      case 'json':
      case 'json_native':
        $value = json_decode($object->value);
        break;

      // Give integer timestamp.
      case 'created':
      case 'changed':
        $value = $object->getValue();
        if (!empty($value) && is_array($value)) {
          $value = $this->recursiveResetArray($value);
          $value = reset($value);
          $value = intval($value);
        }
        break;

      // Format timestamps into date and time.
      // case 'created':
      // case 'changed':
      //   $value = $object->getValue();
      //   if (!empty($value)) {
      //     $value = \Drupal::service('date.formatter')->format($value[0]['value'], 'short');
      //   }

        /*else {
          // Certain fields could be formatted as 'ago'.
          $value = \Drupal::service('date.formatter')->formatTimeDiffSince($value[0]['value'], ['granularity' => 1]) . ' ago';
        }*/

        //break;

      // Normal handling.
      default:
        $value = $object->getValue();
        // Support for stringable value objects: avoid numerous custom normalizers.
        if (is_object($value) && method_exists($value, '__toString')) {
          $value = (string) $value;
        }
        // If it's an array, break down some excessive keys.
        if (is_array($value)) {
          $value = $this->recursiveResetArray($value);
        }
        break;
    }

    // Reduce base properties that are also singlular cardinality.
    if ($fieldDefinition instanceof \Drupal\Core\Field\BaseFieldDefinition) {
      if (!$fieldDefinition->isMultiple()) {
        if (is_array($value)) {
          $value = reset($value);
        }
      }
    }
    else {
      $storageDef = $fieldDefinition->getFieldStorageDefinition();
      if (!$storageDef->isMultiple()) {
        // It's okay if [] turns to false, because it should be single value anyway (not array).
        // if (is_array($value) && !empty($value)) {
        if (is_array($value)) {
          $value = reset($value);
        }
      }
    }



    return $value;
    /*return [
      'field_type' => $fieldType,
      // 'is_multiple' => $fieldDefinition->getCardinality(),
      'value' => $value
    ];*/
  }

  /**
   * Clears the excess keys in array like 'value'.
   * @TODO Handle for target_id when we don't want to recurse?
   *
   * @param      array  $array  The array
   *
   * @return     array  ( description_of_the_return_value )
   */
  public function recursiveResetArray($array) {
    $key = '';
    foreach ($array as &$items) {
      if (is_array($items)) {
        if (isset($items['value'])) {
          $key = 'value';
        }
        /*elseif (isset($items['target_id'])) {
          $key = 'target_id';
        }*/

        if (!empty($key) && isset($items[$key])) {
          if (is_array($items[$key])) {
            // @TODO This never seems to hit.
            $items = $this->recursiveResetArray($items[$key]);
          }
          else {
            $items = $items[$key];
          }

        }
        else {
          $items = $this->recursiveResetArray($items);
        }
      }
      // The item seems end ready here.
      /*else {
      }*/
    }

    return $array;
  }

}
