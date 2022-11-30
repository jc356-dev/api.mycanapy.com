<?php

namespace Drupal\rest_toolkit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rest_toolkit\RestToolkitEndpointTrait;
use Drupal\user\UserInterface;
// use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class RTEntityController.
 * This should be used as an example, and cloned for custom purposes.
 */
class RTEntityController extends ControllerBase {
  use RestToolkitEndpointTrait;
  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  //protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  /*public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    // $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }*/

  /**
   * Post (Create) an entity.
   *
   * @return object $entity
   *   Return created entity.
   */
  public function post($entity_type, $bundle) {
    $entity = [];
    $this->postData['entity_type'] = $entity_type;
    $this->postData['type'] = $bundle;

    switch ($entity_type) {
      // We don't really need/want to create users here, but this needs to
      // be in place for security measures.
      case 'user':
        // Don't allow creating users from this endpoint.
        return $this->send400('Cannot do that.');
        break;

      default:
        // Need a default permission check.  This covers Nodes and ECK.
        if ($this->user->hasPermission("create {$entity_type} content") ||
          $this->user->hasPermission("create {$entity_type} entities")) {
          $entity = $this->deNormalizePostData(TRUE);
        }
        else {
          return $this->send403("Cannot POST {$entity_type}");
        }
        break;
    }

    return $this->sendNormalizedResponse($entity);
  }

  /**
   * Patch (Update) an entity.
   *
   * @return object $entity
   *   Return updated entity.
   */
  public function patch($entity_type, $id) {
    $entity = [];
    $this->postData['entity_type'] = $entity_type;
    // $this->postData['type'] = $bundle;
    $this->postData['id'] = $id;
    $entity = $this->loadEntity($entity_type, $id);

    switch ($entity_type) {
      // case 'commerce_store':
      //   break;
      // case 'commerce_product':
      //   break;
      case 'user':
        // Don't allow editing other users without administrative privilege.
        if ($this->user->id() != $id) {
          if (!$this->user->hasPermission('administer users')) {
            return $this->send403('Cannot edit other users.');
          }
        }

        $this->handleUserPass($entity);

        // Prevent some fields on user that could cause issues.
        $stopFields = [
          'init',
          'changed',
          'created',
          'login',
          'preferred_admin_langcode',
          'role_change',
          'roles',
          'status',
          'uid',
          'user_picture',
          'uuid'
        ];

        foreach ($stopFields as $stopField) {
          unset($this->postData[$stopField]);
        }

        // This prevents deleting existing field values.
        // @TODO: Allow a flag to unset fields?
        /*foreach ($this->postData as $fieldName => $postedData) {
          if (empty($postedData)) {
            unset($this->postData[$fieldName]);
          }
        }*/

        unset($entity);
        $entity = $this->deNormalizePostData(TRUE);
        break;

      default:
        $permissions = [
          "edit any {$entity_type} content",
          "edit any {$entity_type} entities",
        ];

        // If it is authored by this user, different permission check.
        if (!empty($entity->uid) && $entity->uid == $this->user->id()) {
          $permissions = [
            "edit own {$entity_type} content",
            "edit own {$entity_type} entities",
          ];
        }

        unset($entity);

        // Check permission.
        foreach ($permissions as $permission) {
          if ($this->user->hasPermission($permission)) {
            $entity = $this->deNormalizePostData(TRUE);
            break;
          }
        }
        break;
    }

    return $this->sendNormalizedResponse($entity);
  }

  /**
   * Get (Load) an entity.
   *
   * @return object $entity
   *   Return loaded entity.
   */
  public function get($entity_type, $id) {
    $entity = [];
    $stopFields = [];
    $usesOwnerTrait = TRUE;

    switch ($entity_type) {
      // case 'commerce_store':
      //   break;
      // case 'commerce_product':
      //   break;
      case 'user':
        $usesOwnerTrait = FALSE;
        $entity = $this->loadEntity($entity_type, $id);

        // Prevent some fields on user that could cause issues.
        $stopFields = [
          'init',
          'changed',
          'created',
          'pass',
          'login',
          'preferred_admin_langcode',
          'role_change',
        ];

        break;

      default:
        $entity = $this->loadEntity($entity_type, $id);
        break;
    }

    // $usesOwnerTrait = in_array(Drupal\user\EntityOwnerTrait::class, class_uses($entity::class));

    // If user is the owner of this entity, inform normalizer so it can send
    // more sensitive fields.
    if ($usesOwnerTrait && $entity->getOwnerId() == $this->user->id()) {
      $this->normalizerContext['is_owner'] = TRUE;
    }
    elseif (!$usesOwnerTrait && $entity_type == 'user') {
      if ($entity->id() == $this->user->id()
        || $this->user->hasPermission('administer users')) {
        $this->normalizerContext['is_owner'] = TRUE;
      }
    }

    return $this->sendNormalizedResponse($entity, $stopFields);
  }

  /**
   * Helper function to load an entity;
   *
   * @param      <type>  $entity_type  The entity type
   * @param      <type>  $id           The identifier
   *
   * @return     <type>  ( description_of_the_return_value )
   */
  public function loadEntity($entity_type, $id) {
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($id);

    if (empty($entity)) {
      // If we couldn't load the entity.
      $message = 'Could not load ' . $entity_type .
        ' ID: ' . $id;
      return $this->send400($message);
    }

    return $entity;
  }

  /**
   * Sends email notifications if necessary for user that was registered.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   */
  protected function sendEmailNotifications(UserInterface $account) {
    // $approval_settings = $this->userSettings->get('register');
    $userSetings = \Drupal::config('user.settings');
    $approval_settings = $userSetings->get('register');
    // No e-mail verification is required. Activating the user.
    if ($approval_settings == UserInterface::REGISTER_VISITORS) {
      if (!$userSetings->get('verify_mail')) {
        // No administrator approval required.
        _user_mail_notify('register_no_approval_required', $account);
      }
    }
    // Administrator approval required.
    elseif ($approval_settings == UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL) {
      _user_mail_notify('register_pending_approval', $account);
    }
  }

  protected function handleUserPass($user) {
    // If a new password isn't sent, they aren't actually trying to update password.
    if (empty($this->postData['pass']['value'])) {
      return;
    }

    // They used a one time login link, and should not be required to enter
    // existing password.
    if (!empty($this->postData['pass']['pass_reset_token'])) {
      $timeout = \Drupal::config('user.settings')->get('password_reset_timeout');
      $tempstore = \Drupal::service('tempstore.private')->get('rest_toolkit', $timeout);
      $storedToken = $tempstore->get('pass_reset_token');

      // If tokens don't match.
      if (empty($storedToken) || $storedToken != $this->postData['pass']['pass_reset_token']) {
        $error = 'You have tried to use an invalid or expired one time login link.';
        return $this->send400($error);
        // throw new UnauthorizedHttpException('Bearer token_type="JWT"', $error);
      }
      // Set new password.
      $user->setPassword($this->postData['pass']['value']);
      // Now remove that token from temp storage.
      $tempstore->delete('pass_reset_token');
    }
    elseif (empty($this->postData['pass']['existing'])) {
      $error = 'You must enter your existing password to change your password.';
      //$this->utils->softError($error);
      return $this->send400($error);
      // throw new UnauthorizedHttpException('Bearer token_type="JWT"', $error);
      //continue 2;
    }
    else {
      // Verify existing password before allowing user to change it.
      $this->checkExistingPassword($user, $this->postData['pass']['existing']);
      // Set new password.
      $user->setPassword($this->postData['pass']['value']);
    }
  }

  /**
   * Verify existing password before allowing user to change certain fields.
   *
   * @param  [type] $user     [description]
   * @param  [type] $password [description]
   * @return [type]           [description]
   */
  public function checkExistingPassword($user, $password) {
    $checkExisting = \Drupal::service('password')
      ->check(trim($password), trim($user->get('pass')->value));

    if (!$checkExisting) {
      $error = 'The existing password you entered was incorrect.';
      $this->send400($error);
    }

    return TRUE;
  }

}
