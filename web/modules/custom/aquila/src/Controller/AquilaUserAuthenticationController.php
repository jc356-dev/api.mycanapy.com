<?php

namespace Drupal\aquila\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
// JWT stuff.
use Drupal\jwt_auth_issuer\Controller\JwtAuthIssuerController;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\Core\Session\AnonymousUserSession;
// For recovery pass mail.
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Crypt;

/**
 * Provides controllers for login, login status and logout via HTTP requests.
 */
class AquilaUserAuthenticationController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * String sent in responses, to describe the user as being logged in.
   *
   * @var string
   */
  const LOGGED_IN = 1;

  /**
   * String sent in responses, to describe the user as being logged out.
   *
   * @var string
   */
  const LOGGED_OUT = 0;

  /**
   * The flood controller.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The user authentication.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  protected $serializerFormats = [];

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The JWT Auth Service.
   *
   * @var \Drupal\jwt\Authentication\Provider\JwtAuth
   */
  private $jwtAuth;

  /**
   * Constructs a new AquilaUserAuthenticationController object.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood controller.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(FloodInterface $flood, UserStorageInterface $user_storage, CsrfTokenGenerator $csrf_token, UserAuthInterface $user_auth, RouteProviderInterface $route_provider, Serializer $serializer, array $serializer_formats, LoggerInterface $logger, JwtAuth $jwtAuth) {
    $this->flood = $flood;
    $this->userStorage = $user_storage;
    $this->csrfToken = $csrf_token;
    $this->userAuth = $user_auth;
    $this->serializer = $serializer;
    $this->serializerFormats = $serializer_formats;
    $this->routeProvider = $route_provider;
    $this->logger = $logger;
    $this->jwtAuth = $jwtAuth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    if ($container->hasParameter('serializer.formats') && $container->has('serializer')) {
      $serializer = $container->get('serializer');
      $formats = $container->getParameter('serializer.formats');
    }
    else {
      $formats = ['json'];
      $encoders = [new JsonEncoder()];
      $serializer = new Serializer([], $encoders);
    }

    return new static(
      $container->get('flood'),
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('csrf_token'),
      $container->get('user.auth'),
      $container->get('router.route_provider'),
      $serializer,
      $formats,
      $container->get('logger.factory')->get('user'),
      $container->get('jwt.authentication.jwt')
    );
  }

  /**
   * Logs in a user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response which contains the ID and CSRF token.
   */
  public function login(Request $request) {
    // Destroy any session before proceeding.
    /*$account = \Drupal::currentUser();
    if ($account->isAuthenticated()) {
      \Drupal::service('session_manager')->destroy();
      $account->setAccount(new AnonymousUserSession());
    }*/

    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $credentials = $this->serializer->decode($content, $format);
    if (!isset($credentials['name']) && !isset($credentials['pass'])) {
      throw new BadRequestHttpException('Missing credentials.');
    }

    if (!isset($credentials['name'])) {
      throw new BadRequestHttpException('Missing credentials.name.');
    }
    if (!isset($credentials['pass'])) {
      throw new BadRequestHttpException('Missing credentials.pass.');
    }

    $this->floodControl($request, $credentials['name']);

    // Try to load user by email.
    if ($user = user_load_by_mail($credentials['name'])) {
      $credentials['name'] = $user->getAccountName();
      unset($user);
    }

    if ($this->userIsBlocked($credentials['name'])) {
      throw new BadRequestHttpException('The user has not been activated or is blocked.');
    }

    if ($uid = $this->userAuth->authenticate($credentials['name'], $credentials['pass'])) {
      $this->flood->clear('user.http_login', $this->getLoginFloodIdentifier($request, $credentials['name']));
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->userStorage->load($uid);
      $this->userLoginFinalize($user);

      // Send basic metadata about the logged in user.
      $response_data = [];
      /*if ($user->get('uid')->access('view', $user)) {
        $response_data['current_user']['uid'] = $user->id();
      }
      if ($user->get('roles')->access('view', $user)) {
        $response_data['current_user']['roles'] = $user->getRoles();
      }
      if ($user->get('name')->access('view', $user)) {
        $response_data['current_user']['name'] = $user->getAccountName();
      }*/
      $response_data['csrf_token'] = $this->csrfToken->get('rest');

      $logout_route = $this->routeProvider->getRouteByName('user.logout.http');
      // Trim '/' off path to match \Drupal\Core\Access\CsrfAccessCheck.
      $logout_path = ltrim($logout_route->getPath(), '/');
      $response_data['logout_token'] = $this->csrfToken->get($logout_path);

      // Add JWT token to response data.
      $jwtIssuer = new JwtAuthIssuerController($this->jwtAuth);
      $jwtResponse = $jwtIssuer->tokenResponse();
      // If we didn't get a JWT response.
      if (empty($jwtResponse)) {
        // Return bad request.
        $error = 'SocialRedirectController: User ID:' . $user->id() . ' failed to get JWT token.';
        $this->sendError($error);
      }

      //$response_data['user'] = aquila_load_user_data($user->id());

      $jwt_token = json_decode($jwtResponse->getContent());
      $response_data['jwt'] = $jwt_token->token;

      // Test destroying cookie session before sending them JWT.
      $account = \Drupal::currentUser();
      \Drupal::service('session_manager')->destroy();
      $account->setAccount(new AnonymousUserSession());

      // Remove any headers regarding set cookie.
      // No longer needed - blocking all cookies in Nginx.
      /*foreach(headers_list() as $header) {
        if (preg_match('/Set-Cookie/',$header)) {
          header_remove('Set-Cookie');
        }
      }*/

      $encoded_response_data = $this->serializer->encode($response_data, $format);
      return new Response($encoded_response_data);
    }

    $flood_config = $this->config('user.flood');
    if ($identifier = $this->getLoginFloodIdentifier($request, $credentials['name'])) {
      $this->flood->register('user.http_login', $flood_config->get('user_window'), $identifier);
    }
    // Always register an IP-based failed login event.
    $this->flood->register('user.failed_login_ip', $flood_config->get('ip_window'));
    throw new BadRequestHttpException('Sorry, unrecognized username or password.');
  }

  /**
   * Resets a user password.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function resetPassword(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $credentials = $this->serializer->decode($content, $format);

    // Check if a name or mail is provided.
    if (!isset($credentials['name']) && !isset($credentials['mail'])) {
      throw new BadRequestHttpException('Missing credentials.name or credentials.mail');
    }

    // Load by name if provided.
    if (isset($credentials['name'])) {
      $users = $this->userStorage->loadByProperties(['name' => trim($credentials['name'])]);
    }
    elseif (isset($credentials['mail'])) {
      $users = $this->userStorage->loadByProperties(['mail' => trim($credentials['mail'])]);
    }

    /** @var \Drupal\Core\Session\AccountInterface $account */
    $account = reset($users);
    if ($account && $account->id()) {
      if ($this->userIsBlocked($account->getAccountName())) {
        throw new BadRequestHttpException('The user has not been activated or is blocked.');
      }

      // Send the password reset email.
      $mail = _user_mail_notify('password_reset', $account, $account->getPreferredLangcode());
      if (empty($mail)) {
        throw new BadRequestHttpException('Unable to send email. Contact the site administrator if the problem persists.');
      }
      else {
        $this->logger->notice('Password reset instructions mailed to %name at %email.', ['%name' => $account->getAccountName(), '%email' => $account->getEmail()]);
        return new Response();
      }
    }

    // Error if no users found with provided name or mail.
    throw new BadRequestHttpException('Unrecognized username or email address.');
  }

  public function aquilaTokenResetPassword(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $postData = $this->serializer->decode($content, $format);

    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
     //dd($postData);
    // \Drupal::logger('aquila')->debug($content);

    if (!$user) {
      $error = 'No user found to update.';
      $this->utils->error400($error);
    }

    // If no POST/PATCH data was recieved.
    if (empty($postData)) {
      $error = 'Edit data is empty.';
      $this->utils->error400($error);
    }

    // If a new password isn't sent, they aren't actually trying to update password.
    if (empty($postData['value'])) {
      throw new BadRequestHttpException('Missing new password.');
    }

    // They used a one time login link, and should not be required to enter
    // existing password.
    if (!empty($postData['pass_reset_token'])) {
      $timeout = $this->config('user.settings')->get('password_reset_timeout');
      $tempstore = \Drupal::service('tempstore.private')->get('aquila', $timeout);
      $storedToken = $tempstore->get('pass_reset_token');

      // If tokens don't match.
      if (empty($storedToken) || $storedToken != $postData['pass_reset_token']) {
        $error = 'You have tried to use an invalid or expired one time login link.';
        throw new UnauthorizedHttpException('Bearer token_type="JWT"', $error);
      }
      // Set new password.
      $user->setPassword($postData['value']);
      \Drupal::logger('aquila')->debug($postData['value']);
      $user->save();
      // Now remove that token from temp storage.
      $tempstore->delete('pass_reset_token');
    }
    // elseif (empty($postData['existing'])) {
    //   $error = 'You must enter your existing password to change your password.';
    //   //$this->utils->softError($error);
    //   throw new UnauthorizedHttpException('Bearer token_type="JWT"', $error);
    //   //continue 2;
    // }
    // else {
    //   // Verify existing password before allowing user to change it.
    //   $this->checkExistingPassword($user, $postData['existing']);
    //   // Set new password.
    //   $user->setPassword($postData['value']);
    // }
    $encoded_response_data = $this->serializer->encode('Your new password has been saved, make sure to write it down this time :)', $format);
    return new Response($encoded_response_data);
  }

  public function aquilaUliPassword(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $credentials = $this->serializer->decode($content, $format);

    // Check if a name or mail is provided.
    if (!isset($credentials['account'])) {
      throw new BadRequestHttpException('Missing credentials.account');
    }

    // Atempt to find user by mail first, then name if that fails.
    $users = $this->userStorage->loadByProperties(['mail' => trim($credentials['account'])]);

    if (empty($users)) {
      $users = $this->userStorage->loadByProperties(['name' => trim($credentials['account'])]);
    }

    if (empty($users)) {
      // Error if no users found with provided name or mail.
      throw new BadRequestHttpException('Unrecognized username or email address.');
    }

    /** @var \Drupal\Core\Session\AccountInterface $user */
    $user = reset($users);
    if ($user && $user->id()) {
      if ($this->userIsBlocked($user->getAccountName())) {
        throw new BadRequestHttpException('The user has not been activated or is blocked.');
      }
    }

    // Generate ULI.
    /*$url = user_pass_reset_url($user);
    // Replace domain @todo from config(frontend.url).
    global $base_url;
    $url = str_replace($base_url, 'https://aquilalife.com', $url);
    $urlExplode = explode('/', $url);

    // Generate params from uli portions.
    $params[] = '?hash=' . array_pop($urlExplode);
    $params[] = 'timestamp=' . array_pop($urlExplode);
    $params[] = 'uid=' . array_pop($urlExplode);

    $url = implode('/', $urlExplode);
    $urlParams = implode('&', $params);
    $url .= $urlParams;*/

    $url = aquila_create_user_uli($user);

    // Subject.
    $subject = $this->t('Password reset link inside');
    // Body.
    $message = $this->t('A request to reset the password for your account has been made.');
    $message .= '<br /> <br />';
    $message .= $this->t('This link can only be used once to log in.  It expires after 24 hours and nothing will happen if it is not used.  You should consider changing your password afterwards.');
    $message .= '<br /> <br />';
    $message .= '<a rel="nofollow" href="' . $url . '">' . $url . '</a>';

    //dd($message);

    aquila_send_mail($user, $message, $subject, $key = 'aquila_passuli');

    return new Response();



  }

  /**
   * Verifies if the user is blocked.
   *
   * @param string $name
   *   The username.
   *
   * @return bool
   *   TRUE if the user is blocked, otherwise FALSE.
   */
  protected function userIsBlocked($name) {
    return user_is_blocked($name);
  }

  /**
   * Finalizes the user login.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   */
  protected function userLoginFinalize(UserInterface $user) {
    user_login_finalize($user);
  }

  /**
   * Logs out a user.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function logout() {
    $this->userLogout();
    return new Response(NULL, 204);
  }

  /**
   * Logs the user out.
   */
  protected function userLogout() {
    user_logout();
  }

  /**
   * Checks whether a user is logged in or not.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function loginStatus() {
    if ($this->currentUser()->isAuthenticated()) {
      $response = new Response(self::LOGGED_IN);
    }
    else {
      $response = new Response(self::LOGGED_OUT);
    }
    $response->headers->set('Content-Type', 'text/plain');
    return $response;
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
  protected function getRequestFormat(Request $request) {
    $format = $request->getRequestFormat();
    if (!in_array($format, $this->serializerFormats)) {
      throw new BadRequestHttpException("Unrecognized format: $format.");
    }
    return $format;
  }

  /**
   * Enforces flood control for the current login request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $username
   *   The user name sent for login credentials.
   */
  protected function floodControl(Request $request, $username) {
    $flood_config = $this->config('user.flood');
    if (!$this->flood->isAllowed('user.failed_login_ip', $flood_config->get('ip_limit'), $flood_config->get('ip_window'))) {
      throw new AccessDeniedHttpException('Access is blocked because of IP based flood prevention.', NULL, Response::HTTP_TOO_MANY_REQUESTS);
    }

    if ($identifier = $this->getLoginFloodIdentifier($request, $username)) {
      // Don't allow login if the limit for this user has been reached.
      // Default is to allow 5 failed attempts every 6 hours.
      if (!$this->flood->isAllowed('user.http_login', $flood_config->get('user_limit'), $flood_config->get('user_window'), $identifier)) {
        if ($flood_config->get('uid_only')) {
          $error_message = sprintf('There have been more than %s failed login attempts for this account. It is temporarily blocked. Try again later or request a new password.', $flood_config->get('user_limit'));
        }
        else {
          $error_message = 'Too many failed login attempts from your IP address. This IP address is temporarily blocked.';
        }
        throw new AccessDeniedHttpException($error_message, NULL, Response::HTTP_TOO_MANY_REQUESTS);
      }
    }
  }

  /**
   * Gets the login identifier for user login flood control.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $username
   *   The username supplied in login credentials.
   *
   * @return string
   *   The login identifier or if the user does not exist an empty string.
   */
  protected function getLoginFloodIdentifier(Request $request, $username) {
    $flood_config = $this->config('user.flood');
    $accounts = $this->userStorage->loadByProperties(['name' => $username, 'status' => 1]);
    if ($account = reset($accounts)) {
      if ($flood_config->get('uid_only')) {
        // Register flood events based on the uid only, so they apply for any
        // IP address. This is the most secure option.
        $identifier = $account->id();
      }
      else {
        // The default identifier is a combination of uid and IP address. This
        // is less secure but more resistant to denial-of-service attacks that
        // could lock out all users with public user names.
        $identifier = $account->id() . '-' . $request->getClientIp();
      }
      return $identifier;
    }
    return '';
  }

  /**
   * Log error in our DB, and send Bad HTTP request.
   * @param string $error
   *   The error message.
   *
   * @return BadRequestHttpException.
   */
  protected function sendError($error = '') {
    \Drupal::logger('aquila')->error($error);
    throw new BadRequestHttpException($error);
  }

  /*protected function uli() {

  }*/

  /**
   * Validates user, hash, and timestamp; logs the user in if correct.
   *
   * @param int $uid
   *   User ID of the user requesting reset.
   * @param int $timestamp
   *   The current timestamp.
   * @param string $hash
   *   Login link hash.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Returns a redirect to the user edit form if the information is correct.
   *   If the information is incorrect redirects to 'user.pass' route with a
   *   message for the user.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If $uid is for a blocked user or invalid user ID.
   */
  public function uliLogin(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $postData = $this->serializer->decode($content, $format);

    // @TODO Rewrite these items with our params.
    $uid = $postData['uid'];
    $timestamp = $postData['timestamp'];
    $hash = $postData['hash'];

    // The current user is not logged in, so check the parameters.
    $current = REQUEST_TIME;
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->userStorage->load($uid);

    // Verify that the user exists and is active.
    if ($user === NULL || !$user->isActive() || empty($postData)) {
      // Blocked or invalid user ID, so deny access. The parameters will be in
      // the watchdog's URL for the administrator to check.
      $error = $this->t('You have tried to use a one-time login link that is invalid.');
      \Drupal::logger('aquila')->error($error);
      throw new AccessDeniedHttpException($error);
    }

    // Time out, in seconds, until login URL expires.
    $timeout = $this->config('user.settings')->get('password_reset_timeout');
    // No time out for first time login.
    if ($user->getLastLoginTime() && $current - $timestamp > $timeout) {
      $error = $this->t('You have tried to use a one-time login link that has expired.');
      \Drupal::logger('aquila')->error($error);
      throw new AccessDeniedHttpException($error);
      //$this->messenger()->addError($this->t('You have tried to use a one-time login link that has expired. Please request a new one using the form below.'));
      //return $this->redirect('user.pass');
    }
    elseif ($user->isAuthenticated() && ($timestamp >= $user->getLastLoginTime()) && ($timestamp <= $current) && Crypt::hashEquals($hash, user_pass_rehash($user, $timestamp))) {
      //user_login_finalize($user);
      $this->userLoginFinalize($user);
      $this->logger->notice('User %name used one-time login link at time %timestamp.', ['%name' => $user->getUsername(), '%timestamp' => $timestamp]);
      //$this->messenger()->addStatus($this->t('You have just used your one-time login link. It is no longer necessary to use this link to log in. Please change your password.'));
      // Let the user's password be changed without the current password
      // check.

      /*$token = Crypt::randomBytesBase64(55);
      $_SESSION['pass_reset_' . $user->id()] = $token;*/

      $token = Crypt::randomBytesBase64(55);
      $response_data = $this->generateJWT($user, $token);

      $encoded_response_data = $this->serializer->encode($response_data, $format);
      return new Response($encoded_response_data);

      /*return $this->redirect(
        'entity.user.edit_form',
        ['user' => $user->id()],
        [
          'query' => ['pass-reset-token' => $token],
          'absolute' => TRUE,
        ]
      );*/
    }


    $error = $this->t('You have tried to use a one-time login link that has either been used or is no longer valid.');
    \Drupal::logger('aquila')->error($error);
    throw new AccessDeniedHttpException($error);
  }


  public function generateJWT($user, $passResetToken = '') {
    // Send basic metadata about the logged in user.
      $response_data = [];
      /*if ($user->get('uid')->access('view', $user)) {
        $response_data['current_user']['uid'] = $user->id();
      }
      if ($user->get('roles')->access('view', $user)) {
        $response_data['current_user']['roles'] = $user->getRoles();
      }
      if ($user->get('name')->access('view', $user)) {
        $response_data['current_user']['name'] = $user->getAccountName();
      }*/
      $response_data['csrf_token'] = $this->csrfToken->get('rest');

      $logout_route = $this->routeProvider->getRouteByName('user.logout.http');
      // Trim '/' off path to match \Drupal\Core\Access\CsrfAccessCheck.
      $logout_path = ltrim($logout_route->getPath(), '/');
      $response_data['logout_token'] = $this->csrfToken->get($logout_path);

      // Add JWT token to response data.
      $jwtIssuer = new JwtAuthIssuerController($this->jwtAuth);
      $jwtResponse = $jwtIssuer->tokenResponse();
      // If we didn't get a JWT response.
      if (empty($jwtResponse)) {
        // Return bad request.
        $error = 'SocialRedirectController: User ID:' . $user->id() . ' failed to get JWT token.';
        $this->sendError($error);
      }

      //$response_data['user'] = aquila_load_user_data($user->id());

      $jwt_token = json_decode($jwtResponse->getContent());
      $response_data['jwt'] = $jwt_token->token;

      if (!empty($passResetToken)) {
        // Store token in tempStorage.
        $timeout = $this->config('user.settings')->get('password_reset_timeout');
        $tempstore = \Drupal::service('tempstore.private')->get('aquila', $timeout);
        $tempstore->set('pass_reset_token', $passResetToken);
        $response_data['pass_reset_token'] = $passResetToken;
      }

      // Test destroying cookie session before sending them JWT.
      $account = \Drupal::currentUser();
      \Drupal::service('session_manager')->destroy();
      $account->setAccount(new AnonymousUserSession());

      return $response_data;
  }

}
