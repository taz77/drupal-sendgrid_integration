<?php

namespace Drupal\sendgrid_integration\Plugin\Mail;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Html2Text\Html2Text;
use SendGrid\Client;
use SendGrid\Exception\SendgridException;
use SendGrid\Mail\Attachment;
use SendGrid\Mail\BccSettings;
use SendGrid\Mail\Mail;
use SendGrid\Mail\MailSettings;
use SendGrid\Mail\Personalization;
use SendGrid\Mail\ReplyTo;
use SendGrid\Mail\SandBoxMode;
use SendGrid\Mail\Subject;

use SendGrid\Mail\To;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @file
 * Implements Drupal MailSystemInterface.
 *
 * @Mail(
 *   id = "sendgrid_integration",
 *   label = @Translation("Sendgrid Integration"),
 *   description = @Translation("Sends the message using Sendgrid API.")
 * )
 */
class SendGridMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  const SENDGRID_INTEGRATION_EMAIL_REGEX = '/^\s*"?(.+?)"?\s*<\s*([^>]+)\s*>$/';

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger service for the sendgrid_integration module.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * SendGridMailSystem constructor.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory service.
   * @param \Drupal\Core\logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerChannelFactory, ModuleHandlerInterface $moduleHandler, QueueFactory $queueFactory) {
    $this->configFactory = $configFactory;
    $this->logger = $loggerChannelFactory->get('sendgrid_integration');
    $this->moduleHandler = $moduleHandler;
    $this->queueFactory = $queueFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('module_handler'),
      $container->get('queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    // Join message array.
    $message['body'] = implode("\n\n", $message['body']);

    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    # Begin by creating instances of objects needed.
    $personalization0 = new Personalization();
    $sendgrid_message = new Mail();
    $mail_settings = new MailSettings();
    $bcc_settings = new BccSettings();
    $sandbox_mode = new SandBoxMode();

    $site_config = $this->configFactory->get('system.site');
    $sendgrid_config = $this->configFactory->get('sendgrid_integration.settings');

    $key_secret = $sendgrid_config->get('apikey');
    if ($this->moduleHandler->moduleExists('key')) {
      $key = \Drupal::service('key.repository')->getKey($key_secret);
      if ($key) {
        $key_value = $key->getKeyValue();
        if ($key_value) {
          $key_secret = $key_value;
        }
      }
    }

    if (empty($key_secret)) {
      // Set a error in the logs if there is no API key.
      $this->logger->error('No API Secret key has been set');
      // Return false to indicate message was not able to send.
      return FALSE;
    }
    $options = [
      'turn_off_ssl_verification' => FALSE,
      'protocol' => 'https',
      'port' => NULL,
      'url' => NULL,
      'raise_exceptions' => FALSE,
    ];
    // Create a new SendGrid object.
    $client = new Client($key_secret, $options);

    $sitename = $site_config->get('name');

    // If this is a password reset. Bypass spam filters.
    if (strpos($message['id'], 'password')) {
      $spam_check = new SpamCheck();
      $spam_check->setEnable(FALSE);
    }
    // If this is a Drupal Commerce message. Bypass spam filters.
    if (strpos($message['id'], 'commerce')) {
      $spam_check = new SpamCheck();
      $spam_check->setEnable(FALSE);
    }

    # Add UID metadata to the message that matches the drupal user ID.
    if (isset($message['params']['account']->uid)) {
      $sendgrid_message->addCustomArg("uid", strval($message['params']['account']->uid));
    }

    // Checking if 'From' email-address already exists.
    if (isset($message['headers']['From'])) {
      $fromaddrarray = $this->parseAddress($message['headers']['From']);
      $data['from'] = $fromaddrarray[0];
      $data['fromname'] = $fromaddrarray[1];
    }
    else {
      $data['from'] = $site_config->get('mail');
      $data['fromname'] = $sitename;
    }

    // Check if $send is set to be true.
    if ($message['send'] != 1) {
      $this->logger->notice('Email was not sent because send value was disabled.');
      return TRUE;
    }
    // Build the Sendgrid mail object.
    // The message MODULE and ID is used for the Category. Category is the only
    // thing in the Sendgrid UI you can use to sort mail.
    // This is an array of categories for Sendgrid statistics.
    $categories = [
      $sitename,
      $message['module'],
      $message['id'],
    ];

    // Allow other modules to modify categories.
    $this->moduleHandler->invokeAll('sendgrid_integration_categories_alter', [
      $message,
      $categories,
    ]);

    // Check if we got any variable back.
    if (!empty($result)) {
      $categories = $result;
    }

    $sendgrid_message->addCategories($categories);

    $personalization0->setSubject($message['subject']);
    $sendgrid_message->setFrom($data['from']);

    # Set the from address and add a name if it exists.
    if (!empty($data['fromname'])) {
      $sendgrid_message->setFromName($data['fromname']);
      $sendgrid_message->setFrom($data['from'], $data['fromname']);
    }
    else {
      $sendgrid_message->setFrom($data['from']);
    }

    // If there are multiple recipients we have to explode and walk the values.
    if (strpos($message['to'], ',')) {
      $sendtosarry = explode(',', $message['to']);
      foreach ($sendtosarry as $value) {
        $sendtoarrayparsed = $this->parseAddress($value);
        $personalization0->addTo(new To($sendtoarrayparsed[0], isset($sendtoarrayparsed[1])) ? $sendtoarrayparsed[1] : NULL);
      }
    }
    else {
      $toaddrarray = $this->parseAddress($message['to']);
      $personalization0->addTo(new To($toaddrarray[0], isset($toaddrarray[1])) ? $toaddrarray[1] : NULL);
    }

    // Add cc and bcc in mail if they exist.
    $cc_bcc_keys = ['cc', 'bcc'];
    $address_cc_bcc = [];

    // Beginning of consolidated header parsing.
    foreach ($message['headers'] as $key => $value) {
      switch (mb_strtolower($key)) {
        case 'content-type':
          // Parse several values on the Content-type header, storing them in an array like
          // key=value -> $vars['key']='value'.
          $vars = explode(';', $value);
          foreach ($vars as $i => $var) {
            if ($cut = strpos($var, '=')) {
              $new_var = trim(mb_strtolower(mb_substr($var, $cut + 1)));
              $new_key = trim(mb_substr($var, 0, $cut));
              unset($vars[$i]);
              $vars[$new_key] = $new_var;
            }
          }
          // If $vars is empty then set an empty value at index 0 to avoid a PHP warning in the next statement.
          $vars[0] = isset($vars[0]) ? $vars[0] : '';
          // Nested switch to process the various content types. We only care
          // about the first entry in the array.
          switch ($vars[0]) {
            case 'text/plain':
              // The message includes only a plain text part.
              $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($message['body'])));
              break;

            case 'text/html':
              // Ensure body is a string before using it as HTML.
              $body = $message['body'];
              if ($body instanceof MarkupInterface) {
                $body = $body->__toString();
              }

              // The message includes only an HTML part.
              $sendgrid_message->addContent('text/html', $body);

              // Also include a text only version of the email.
              $converter = new Html2Text($message['body']);
              $body_plain = $converter->getText();
              $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail($body_plain));
              break;


            case 'multipart/alternative':
              // Get the boundary ID from the Content-Type header.
              $boundary = $this->getSubString($message['body'], 'boundary', '"', '"');

              // Parse text and HTML portions.
              // Split the body based on the boundary ID.
              $body_parts = $this->boundrySplit($message['body'], $boundary);
              foreach ($body_parts as $body_part) {
                // If plain/text within the body part, add it to $mailer->AltBody.
                if (strpos($body_part, 'text/plain')) {
                  // Clean up the text.
                  $body_part = trim($this->removeHeaders(trim($body_part)));
                  // Include it as part of the mail object.
                  $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($body_part)));
                }
                // If plain/html within the body part, add it to $mailer->Body.
                elseif (strpos($body_part, 'text/html')) {
                  // Clean up the text.
                  $body_part = trim($this->removeHeaders(trim($body_part)));
                  // Include it as part of the mail object.
                  $sendgrid_message->addContent('text/html', $body_part);
                }
              }
              break;

            case 'multipart/mixed':
              // Get the boundary ID from the Content-Type header.
              $boundary = $this->getSubString($value, 'boundary', '"', '"');
              // Split the body based on the boundary ID.
              $body_parts = $this->boundrySplit($message['body'], $boundary);

              // Parse text and HTML portions.
              foreach ($body_parts as $body_part) {
                if (strpos($body_part, 'multipart/alternative')) {
                  // Get the second boundary ID from the Content-Type header.
                  $boundary2 = $this->getSubString($body_part, 'boundary', '"', '"');
                  // Clean up the text.
                  $body_part = trim($this->removeHeaders(trim($body_part)));
                  // Split the body based on the internal boundary ID.
                  $body_parts2 = $this->boundrySplit($body_part, $boundary2);

                  // Process the internal parts.
                  foreach ($body_parts2 as $body_part2) {
                    // If plain/text within the body part, add it to $mailer->AltBody.
                    if (strpos($body_part2, 'text/plain')) {
                      // Clean up the text.
                      $body_part2 = trim($this->removeHeaders(trim($body_part2)));
                      $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($body_part2)));
                    }
                    // If plain/html within the body part, add it to $mailer->Body.
                    elseif (strpos($body_part2, 'text/html')) {
                      // Get the encoding.
                      $body_part2_encoding = trim($this->getSubString($body_part2, 'Content-Transfer-Encoding', ':', "\n"));
                      // Clean up the text.
                      $body_part2 = trim($this->removeHeaders(trim($body_part2)));
                      // Check whether the encoding is base64, and if so, decode it.
                      if (mb_strtolower($body_part2_encoding) == 'base64') {
                        // Save the decoded HTML content.
                        $sendgrid_message->addContent('text/html', base64_decode($body_part2));
                      }
                      else {
                        // Save the HTML content.
                        $sendgrid_message->addContent('text/html', $body_part2);
                      }
                    }
                  }
                }
                else {
                  // This parses the message if there is no internal content
                  // type set after the multipart/mixed.
                  // If text/plain within the body part, add it to $mailer->Body.
                  if (strpos($body_part, 'text/plain')) {
                    // Clean up the text.
                    $body_part = trim($this->removeHeaders(trim($body_part)));
                    // Set the text message.
                    $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($body_part)));
                  }
                  // If text/html within the body part, add it to $mailer->Body.
                  elseif (strpos($body_part, 'text/html')) {
                    // Clean up the text.
                    $body_part = trim($this->removeHeaders(trim($body_part)));
                    // Set the HTML message.
                    $sendgrid_message->addContent('text/html', $body_part);
                  }
                }
              }
              break;

            default:
              // Everything else is unknown so we log and send the message as text.
              \Drupal::messenger()
                ->addError(t('The %header of your message is not supported by SendGrid and will be sent as text/plain instead.', ['%header' => "Content-Type: $value"]));
              $this->logger->error("The Content-Type: $value of your message is not supported by PHPMailer and will be sent as text/plain instead.");
              // Force the email to be text.
              $sendgrid_message->addContent('text/plain', MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($message['body'])));
          }
          break;
          // End Content-type parsing

        case 'reply-to':
          $sendreplyto = $this->parseAddress($message['headers'][$key]);
          $reply_to = new ReplyTo($sendreplyto[0], isset($sendreplyto[1]) ? $sendreplyto[1] : NULL);
          $sendgrid_message->setReplyTo($reply_to);
          break;
      }

      // Handle latter case issue for cc and bcc key.
      if (in_array(mb_strtolower($key), $cc_bcc_keys)) {
        $mail_ids = explode(',', $value);
        foreach ($mail_ids as $mail_id) {
          [$mail_cc_address, $cc_name] = $this->parseAddress($mail_id);
          $address_cc_bcc[mb_strtolower($key)][] = [
            'mail' => $mail_cc_address,
            'name' => $cc_name,
          ];
        }
      }
    }
    if (array_key_exists('cc', $address_cc_bcc)) {
      foreach ($address_cc_bcc['cc'] as $item) {
        $sendgrid_message->addCc($item['mail']);
        $sendgrid_message->addCcName($item['name']);
      }
    }
    if (array_key_exists('bcc', $address_cc_bcc)) {
      foreach ($address_cc_bcc['bcc'] as $item) {
        $sendgrid_message->addBcc($item['mail']);
        $sendgrid_message->addBccName($item['name']);
      }
    }

    // Prepare message attachments and params attachments.
    $attachments = [];
    if (isset($message['attachments']) && !empty($message['attachments'])) {
      foreach ($message['attachments'] as $attachmentitem) {
        if (is_file($attachmentitem)) {
          $attachments[$attachmentitem] = $attachmentitem;
        }
      }
    }
    elseif (isset($message['params']['attachments']) && !empty($message['params']['attachments'])) {
      foreach ($message['params']['attachments'] as $attachment) {
        // Get filepath.
        if (isset($attachment['filepath']) && is_file($attachment['filepath'])) {
          $filepath = $attachment['filepath'];
        }
        elseif (isset($attachment['file']) && $attachment['file'] instanceof FileInterface) {
          $filepath = \Drupal::service('file_system')
            ->realpath($attachment['file']->getFileUri());
        }
        else {
          continue;
        }

        // Get filename.
        if (isset($attachment['filename'])) {
          $filename = $attachment['filename'];
        }
        else {
          $filename = basename($filepath);
        }

        // Attach file.
        $attachments[$filename] = $filepath;
      }
    }

    // If we have attachments, add them.
    if (!empty($attachments)) {
      $sendgrid_message->setAttachments($attachments);
    }

    // Add template ID.
    if (isset($message['sendgrid']['template_id'])) {
      $sendgrid_message->setTemplateId($message['sendgrid']['template_id']);
    }

    // Add substitutions.
    if (isset($message['sendgrid']['substitutions'])) {
      $sendgrid_message->setSubstitutions($message['sendgrid']['substitutions']);
    }


    // Add the finished personalization object.
    $sendgrid_message->addPersonalization($personalization0);


    // Lets try and send the message and catch the error.
    try {
      $response = $client->send($sendgrid_message);
    }
    catch (\Exception $e) {
      $this->logger->error('Sending emails to Sendgrid service failed with error code ' . $e->getCode());
      if ($e instanceof Exception) {
        foreach ($e->getErrors() as $error_info) {
          $this->logger->error('Sendgrid generated error ' . $error_info);
        }
      }
      else {
        $this->logger->error($e->getMessage());
      }
      // Add message to queue if reason for failing was timeout or
      // another valid reason. This adds more error tolerance.
      $codes = [
        -110,
        404,
        408,
        500,
        502,
        503,
        504,
      ];
      if (in_array($e->getCode(), $codes)) {
        $this->queueFactory->get('SendGridResendQueue')->createItem($message);
      }
      return FALSE;
    }
    // Creating hook, allowing other modules react on sent email.
    $hook_args = [$message['to'], $response];
    $this->moduleHandler->invokeAll('sendgrid_integration_sent', $hook_args);

    if ($response->getCode() == 200) {
      // If the code is 200 we are good to finish and proceed.
      return TRUE;
    }
    // Default to low. Sending failed.
    $this->logger->error('Sending emails to Sendgrid service failed with error message %message.',
      ['%message' => $response->getBody()->errors[0]]);
    return FALSE;
  }

  /**
   * Returns a string that is contained within another string.
   *
   * Returns the string from within $source that is some where after $target
   * and is between $beginning_character and $ending_character.
   *
   * Swiped from SMTP module. Thanks!
   *
   * @param string $source
   *   A string containing the text to look through.
   * @param string $target
   *   A string containing the text in $source to start looking from.
   * @param string $beginning_character
   *   A string containing the character just before the sought after text.
   * @param string $ending_character
   *   A string containing the character just after the sought after text.
   *
   * @return string
   *   A string with the text found between the $beginning_character and the
   *   $ending_character.
   */
  protected function getSubString($source, $target, $beginning_character, $ending_character) {
    $search_start = strpos($source, $target) + 1;
    $first_character = strpos($source, $beginning_character, $search_start) + 1;
    $second_character = strpos($source, $ending_character, $first_character) + 1;
    $substring = mb_substr($source, $first_character, $second_character - $first_character);
    $string_length = mb_strlen($substring) - 1;

    if ($substring[$string_length] == $ending_character) {
      $substring = mb_substr($substring, 0, $string_length);
    }

    return $substring;
  }

  /**
   * Splits the input into parts based on the given boundary.
   *
   * Swiped from Mail::MimeDecode, with modifications based on Drupal's coding
   * standards and this bug report: http://pear.php.net/bugs/bug.php?id=6495
   *
   * @param string $input
   *   A string containing the body text to parse.
   * @param string $boundary
   *   A string with the boundary string to parse on.
   *
   * @return array
   *   An array containing the resulting mime parts
   */
  protected function boundrySplit($input, $boundary) {
    $parts = [];
    $bs_possible = mb_substr($boundary, 2, -2);
    $bs_check = '\"' . $bs_possible . '\"';

    if ($boundary == $bs_check) {
      $boundary = $bs_possible;
    }

    $tmp = explode('--' . $boundary, $input);

    for ($i = 1; $i < count($tmp); $i++) {
      if (trim($tmp[$i])) {
        $parts[] = $tmp[$i];
      }
    }

    return $parts;
  }

  /**
   * Strips the headers from the body part.
   *
   * @param string $input
   *   A string containing the body part to strip.
   *
   * @return string
   *   A string with the stripped body part.
   */
  protected function removeHeaders($input) {
    $part_array = explode("\n", $input);

    // Will strip these headers according to RFC2045.
    $headers_to_strip = [
      'Content-Type',
      'Content-Transfer-Encoding',
      'Content-ID',
      'Content-Disposition',
    ];
    $pattern = '/^(' . implode('|', $headers_to_strip) . '):/';

    while (count($part_array) > 0) {

      // Ignore trailing spaces/newlines.
      $line = rtrim($part_array[0]);

      // If the line starts with a known header string.
      if (preg_match($pattern, $line)) {
        $line = rtrim(array_shift($part_array));
        // Remove line containing matched header.
        // If line ends in a ';' and the next line starts with four spaces, it's a continuation
        // of the header split onto the next line. Continue removing lines while we have this condition.
        while (substr($line, -1) == ';' && count($part_array) > 0 && substr($part_array[0], 0, 4) == '    ') {
          $line = rtrim(array_shift($part_array));
        }
      }
      else {
        // No match header, must be past headers; stop searching.
        break;
      }
    }

    $output = implode("\n", $part_array);
    return $output;
  }

  /**
   * Split an email address into it's name and address components.
   * Returns an array with the first element as the email address and the
   * second element as the name.
   */
  protected function parseAddress($email) {
    if (preg_match(self::SENDGRID_INTEGRATION_EMAIL_REGEX, $email, $matches)) {
      return [$matches[2], $matches[1]];
    }
    else {
      return [$email, NULL];
    }
  }

}
